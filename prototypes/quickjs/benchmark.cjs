// macOS runner: isolated Chrome per trial; samples PHP + its child processes.
const {spawn, execFileSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const puppeteer = require('puppeteer-core');
const extension = process.env.QUICKJS_EXTENSION || '/opt/homebrew/lib/php/pecl/20250925/quickjs.dylib';
const trials = Number(process.env.BENCH_TRIALS || 5);
const backends = (process.env.BENCH_BACKENDS || 'rialto,native,quickjs').split(',');
const output = path.join(__dirname, 'results/raw');
fs.mkdirSync(output, {recursive: true});
function cpuSeconds(value) {
  const parts = value.split(':').map(Number);
  return parts.reduce((total, part) => total * 60 + part, 0);
}
function sample(rootPid) {
  const rows = execFileSync('/bin/ps', ['-axo', 'pid=,ppid=,rss=,time='], {encoding: 'utf8'}).trim().split('\n').map(line => {
    const [pid, parent, rss, cpu] = line.trim().split(/\s+/);
    return {pid: Number(pid), parent: Number(parent), rss: Number(rss) * 1024, cpu: cpuSeconds(cpu)};
  });
  const selected = new Set([rootPid]);
  for (let changed = true; changed;) {
    changed = false;
    for (const row of rows) if (selected.has(row.parent) && !selected.has(row.pid)) { selected.add(row.pid); changed = true; }
  }
  // rootPid is /usr/bin/time; measure only its PHP child and descendants.
  return rows.filter(row => row.pid !== rootPid && selected.has(row.pid));
}
async function runTrial(backend, trial, fixtureURL) {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, args: ['--no-proxy-server'], protocolTimeout: 15000});
  try {
    const args = ['-l', process.env.PHP_BIN || '/opt/homebrew/bin/php', '-n'];
    if (backend === 'quickjs') args.push('-d', `extension=${extension}`);
    args.push(path.join(__dirname, 'tests/benchmark.php'), backend);
    const child = spawn('/usr/bin/time', args, {detached: true, env: {...process.env, BROWSER_WS: browser.wsEndpoint(), FIXTURE_URL: fixtureURL}});
    let stdout = '', stderr = '', active = false, measurement;
    const seen = new Map();
    const startCpu = new Map();
    let peakRss = 0, steadyPeakRss = 0, samples = 0, startSample = false;
    child.stdout.on('data', chunk => {
      stdout += chunk;
      if (!active && stdout.includes('BENCH_READY\n')) { active = true; startSample = true; for (const [pid, row] of seen) startCpu.set(pid, row.cpu); }
      const line = stdout.split('\n').find(line => line.startsWith('BENCH_RESULT '));
      if (line && !measurement) { measurement = JSON.parse(line.slice(13)); active = false; }
    });
    child.stderr.on('data', chunk => { stderr += chunk; });
    const timer = setInterval(() => {
      try {
        const rows = sample(child.pid);
        const rss = rows.reduce((sum, row) => sum + row.rss, 0);
        peakRss = Math.max(peakRss, rss);
        if (active) { steadyPeakRss = Math.max(steadyPeakRss, rss); samples++; }
        for (const row of rows) {
          if (active || !startSample) seen.set(row.pid, row);
        }
      } catch (e) { stderr += `\nSampler error: ${e.message}\n`; }
    }, 25);
    const deadline = setTimeout(() => { try { process.kill(-child.pid, 'SIGTERM'); } catch {} }, 120000);
    const code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
    clearInterval(timer); clearTimeout(deadline);
    fs.writeFileSync(path.join(output, `${backend}-${trial}.log`), stdout + '\n' + stderr);
    if (code !== 0 || !measurement) throw new Error(`${backend} trial ${trial} failed (${code}): ${stderr.slice(0, 1500)} ${stdout.slice(-1000)}`);
    const time = stderr.match(/([\d.]+) real\s+([\d.]+) user\s+([\d.]+) sys/);
    return {...measurement, trial, chrome: await browser.version(), resources: {
      sampled_tree_peak_rss_bytes: peakRss, sampled_steady_tree_peak_rss_bytes: steadyPeakRss,
      sampled_steady_tree_cpu_ms: [...seen].reduce((sum, [pid, row]) => sum + Math.max(0, row.cpu - (startCpu.get(pid) || 0)) * 1000, 0),
      steady_samples: samples, sampling_interval_ms: 25,
      total_process_tree_cpu_ms: time ? (Number(time[2]) + Number(time[3])) * 1000 : null,
    }};
  } finally { await browser.close(); }
}
(async () => {
  const fixture = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><title>QuickJS fixture</title><p>Benchmark</p>'); });
  await new Promise(resolve => fixture.listen(0, '127.0.0.1', resolve));
  const runs = [];
  try {
    for (let trial = 0; trial < trials; trial++) {
      // Rotate order to distribute warm system caches and thermal drift.
      const order = backends.slice(trial % backends.length).concat(backends.slice(0, trial % backends.length));
      for (const backend of order) {
        console.log(`Running ${backend} ${trial + 1}/${trials}`);
        const run = await runTrial(backend, trial, `http://127.0.0.1:${fixture.address().port}/`);
        runs.push(run);
        fs.writeFileSync(path.join(__dirname, 'results/benchmark.json'), JSON.stringify({platform: os.platform(), arch: os.arch(), cpus: os.cpus()[0].model, timestamp: new Date().toISOString(), runs}, null, 2) + '\n');
        console.log(JSON.stringify({backend, evaluate_ms: run.phases.evaluate.wall_ms, cpu_ms: run.resources.total_process_tree_cpu_ms, rss_mb: run.resources.sampled_tree_peak_rss_bytes / 1048576}));
      }
    }
  } finally { fixture.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
