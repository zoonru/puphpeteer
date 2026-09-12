const path = require('node:path');

// The default no-plugin bundle keeps the bridge but omits the plugin registry
// and stealth evasions. The full adapter remains available for Puppeteer::use().
module.exports = function pluginCore() {
  return {
    name: 'quickjs-core-bundle',
    setup(build) {
      build.onResolve({filter: /^\.\/plugins\/adapter\.js$/}, args => {
        const importer = args.importer.replaceAll('\\', '/');
        if (!importer.endsWith('/js/guest.js')) return;
        return {path: path.join(__dirname, 'puppeteer-core-plugin-stub.js')};
      });
    },
  };
};
