const fs = require('node:fs');
const path = require('node:path');
const {createRequire} = require('node:module');
const {spawnSync} = require('node:child_process');

// Resolve from the application too when invoked through vendor/zoon/puphpeteer.
const localRequire = createRequire(path.join(process.cwd(), 'package.json'));
const {PUPPETEER_REVISIONS} = localRequire('puppeteer-core');
const version = localRequire('puppeteer-core/package.json').version;
const browsers = createRequire(localRequire.resolve('puppeteer-core'))('@puppeteer/browsers');
const cacheDir = path.join(process.cwd(), 'node_modules', '.puphpeteer');
const expectedChrome = PUPPETEER_REVISIONS.chrome;
let completed = false;
process.once('beforeExit', () => {
  if (!completed) {
    console.error('Chrome installation did not finish. Remove node_modules/.puphpeteer and retry npm run browser:install.');
    process.exitCode = 1;
  }
});

(async () => {
  const platform = browsers.detectBrowserPlatform();
  if (!platform) throw new Error('Chrome for Testing is unavailable for this platform; set PUPPETEER_EXECUTABLE_PATH explicitly.');
  const browser = await browsers.install({
    browser: browsers.Browser.CHROME,
    buildId: expectedChrome,
    platform,
    cacheDir,
  });
  const check = spawnSync(browser.executablePath, ['--version'], {encoding: 'utf8', timeout: 15000});
  if (check.status !== 0 || !check.stdout.includes(expectedChrome)) {
    throw new Error('Installed Chrome cannot run. Remove node_modules/.puphpeteer and repeat npm run browser:install. ' + (check.error?.message ?? check.stderr));
  }
  const manifest = {puppeteer: version, buildId: expectedChrome, platform, executable: path.relative(cacheDir, browser.executablePath)};
  fs.writeFileSync(path.join(cacheDir, 'chrome.json.tmp'), JSON.stringify(manifest, null, 2) + '\n');
  fs.renameSync(path.join(cacheDir, 'chrome.json.tmp'), path.join(cacheDir, 'chrome.json'));
  console.log(`Chrome ${expectedChrome} installed: ${browser.executablePath}`);
})().catch(error => { console.error(error.message); process.exitCode = 1; }).finally(() => { completed = true; });
