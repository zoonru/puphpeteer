const esbuild = require('esbuild');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const check = process.argv.includes('--check');
const debug = process.argv.includes('--debug');
const pluginArgument = process.argv.find(arg => arg.startsWith('--plugins='));
const pluginFile = pluginArgument ? path.resolve(root, pluginArgument.slice('--plugins='.length)) : null;
(async () => {
  const buildBundle = async (outfile, core) => {
    const plugins = [require('./plugin-build.cjs')(pluginFile), require('./plugin-cdp-only.cjs')()];
    if (core) plugins.push(require('./plugin-core.cjs')());
    const result = await esbuild.build({
      absWorkingDir: root,
      entryPoints: ['js/guest.js'],
      outfile,
      bundle: true, platform: 'browser', format: 'iife', target: 'esnext',
      minify: !debug, legalComments: debug ? 'eof' : 'none', sourcemap: false, write: false,
      metafile: core,
      external: ['crypto', 'node:*', '@puppeteer/browsers', '../node/NodeWebSocketTransport.js'],
      logLevel: 'info', plugins,
    });
    if (core) {
      const inputs = Object.keys(result.metafile?.inputs ?? {});
      if (inputs.some(input => input.includes('/bidi/') || input.includes('chromium-bidi'))) {
        throw new Error('Core bundle unexpectedly contains WebDriver BiDi modules');
      }
    }
    return result.outputFiles.map(file => ({path: file.path, contents: Buffer.from(file.contents)}));
  };
  const outputs = [
    ...(await buildBundle('resources/puppeteer.js', false)),
    ...(await buildBundle('resources/puppeteer-core.js', true)),
  ];
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
  console.log(check ? 'Bundles are up to date.' : `Bundles written to resources/ (${debug ? 'debug' : 'production'} build).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
