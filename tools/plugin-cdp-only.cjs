const path = require('node:path');

// Puppeteer imports the BiDi connector from the shared connector even when all
// callers use CDP. Replace that one import while building the QuickJS bundle.
// Keeping this as an esbuild plugin avoids patching node_modules and makes the
// unsupported protocol fail explicitly at runtime.
module.exports = function pluginCdpOnly() {
  return {
    name: 'quickjs-cdp-only',
    setup(build) {
      build.onResolve({filter: /^\.\.\/bidi\/BrowserConnector\.js$/}, args => {
        const importer = args.importer.replaceAll('\\', '/');
        if (!importer.endsWith('/puppeteer/common/BrowserConnector.js')) return;

        return {path: path.join(__dirname, 'puppeteer-cdp-bidi-stub.js')};
      });
    },
  };
};
