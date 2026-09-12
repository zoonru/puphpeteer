const esbuild = require('esbuild');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const check = process.argv.includes('--check');
const pluginArgument = process.argv.find(arg => arg.startsWith('--plugins='));
const pluginFile = pluginArgument ? path.resolve(root, pluginArgument.slice('--plugins='.length)) : null;
(async () => {
  const result = await esbuild.build({
    absWorkingDir: root,
    entryPoints: ['js/guest.js'],
    outfile: 'resources/puppeteer.js',
    bundle: true, platform: 'browser', format: 'iife', target: 'esnext',
    minify: false, sourcemap: false, write: false,
    external: ['crypto', 'node:*', '@puppeteer/browsers', '../node/NodeWebSocketTransport.js'],
    logLevel: 'info',
    plugins: [require('./plugin-build.cjs')(pluginFile)],
  });
  const outputs = result.outputFiles.map(file => ({path: file.path, contents: Buffer.from(file.contents)}));
  outputs.push({path: path.join(root, 'resources/manifest.json'), contents: Buffer.from(JSON.stringify({
    puppeteer: require('puppeteer-core/package.json').version,
    chrome: require('puppeteer-core').PUPPETEER_REVISIONS.chrome,
    esbuild: esbuild.version,
    stealth: require('puppeteer-extra-plugin-stealth/package.json').version,
    extension: 'php-quickjs async/native-bridge fork with dispatch',
  }, null, 2) + '\n')});
  const puppeteer = require('puppeteer-core');
  const launchDefaults = Object.fromEntries([true, false, 'shell'].flatMap(headless => [false, true].map(devtools => [
    `${headless}:${devtools}`, puppeteer.defaultArgs({headless, devtools}).filter(arg => arg !== 'about:blank'),
  ])));
  outputs.push({path: path.join(root, 'resources/launch-defaults.json'), contents: Buffer.from(JSON.stringify(launchDefaults, null, 2) + '\n')});
  for (const file of outputs) {
    if (check) {
      if (!fs.existsSync(file.path) || !fs.readFileSync(file.path).equals(file.contents)) {
        throw new Error(`${path.relative(root, file.path)} is missing or outdated; run npm run build and commit resources.`);
      }
    } else {
      fs.mkdirSync(path.dirname(file.path), {recursive: true});
      fs.writeFileSync(file.path, file.contents);
    }
  }
  console.log(check ? 'Bundle and manifest are up to date.' : 'Bundle and manifest written to resources/.');
})().catch(error => { console.error(error); process.exitCode = 1; });
