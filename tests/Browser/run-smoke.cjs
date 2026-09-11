const {spawn} = require('node:child_process');
const http = require('node:http');
const puppeteer = require('puppeteer-core');
const path = require('node:path');
const extension = process.env.QUICKJS_EXTENSION;
if (!extension || !process.env.CHROME_BIN) {
  throw new Error('Set QUICKJS_EXTENSION to the async/native-bridge php_quickjs library and CHROME_BIN to Chrome. See docs/quickjs.md.');
}
(async () => {
  const fixture = http.createServer((req, res) => { res.setHeader('Content-Type', 'text/html'); res.end('<!doctype html><title>QuickJS fixture</title><button id="button" onclick="document.querySelector(\'#result\').textContent=\'clicked\'">Go</button><div id="result"></div>'); });
  await new Promise(resolve => fixture.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN, headless: true, args: ['--no-proxy-server']});
    const child = spawn(process.env.PHP_BIN || 'php', ['-n', '-d', `extension=${extension}`, path.join(__dirname, 'smoke.php')], {stdio: 'inherit', env: {...process.env, BROWSER_WS: browser.wsEndpoint(), FIXTURE_URL: `http://127.0.0.1:${fixture.address().port}`}});
    const timer = setTimeout(() => child.kill('SIGTERM'), 40000);
    let code;
    try {
      code = await new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', resolve); });
    } finally { clearTimeout(timer); }
    if (code !== 0) throw new Error(`PHP smoke test failed (${code})`);
  } finally { await browser?.close(); fixture.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
