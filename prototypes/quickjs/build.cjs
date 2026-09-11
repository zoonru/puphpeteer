const esbuild = require('esbuild');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
  await esbuild.build({
    entryPoints: [path.join(__dirname, 'src/guest.js')],
    outfile: path.join(__dirname, 'build/puppeteer.js'),
    bundle: true, platform: 'browser', format: 'iife', target: 'esnext',
    minify: false, sourcemap: false,
    external: ['crypto', 'node:*', '@puppeteer/browsers', '../node/NodeWebSocketTransport.js'],
    logLevel: 'info',
  });
  fs.writeFileSync(path.join(__dirname, 'build/manifest.json'), JSON.stringify({
    puppeteer: require('puppeteer-core/package.json').version,
    esbuild: esbuild.version,
    extension: 'php-quickjs + explicit Promise jobs patch',
  }, null, 2) + '\n');
})().catch(error => { console.error(error); process.exitCode = 1; });
