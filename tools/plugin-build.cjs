const fs = require('node:fs');
const path = require('node:path');

// These upstream dependencies replace `require` with lazy-cache. Materialize
// their static dependency lists at build time, without a Node loader in QuickJS.
module.exports = function pluginBuild(pluginFile = null) {
  return {name: 'quickjs-plugins', setup(build) {
    build.onResolve({filter: /^puphpeteer-custom-plugins$/}, () => pluginFile
      ? {path: path.resolve(pluginFile)} : {path: 'empty', namespace: 'custom-plugins'});
    build.onLoad({filter: /.*/, namespace: 'custom-plugins'}, () => ({contents: 'export default {};', loader: 'js'}));
    build.onLoad({filter: /(?:clone-deep|shallow-clone)\/utils\.js$/}, ({path: file}) => {
      const source = fs.readFileSync(file, 'utf8');
      const start = source.indexOf("var utils = require('lazy-cache')(require);");
      const end = source.indexOf('require = fn;', start);
      if (start < 0 || end < 0) throw new Error(`Upstream lazy-cache source changed: ${file}`);
      const entries = [...source.slice(start, end).matchAll(/^require\('([^']+)'(?:, '([^']+)')?\);/gm)].map(([,module,name]) => {
        name ||= module.replace(/-(\w)/g, (_,letter) => letter.toUpperCase());
        return `${JSON.stringify(name)}: require(${JSON.stringify(module)})`;
      });
      if (!entries.length) throw new Error(`No static dependencies found: ${file}`);
      return {contents: source.slice(0,start) + `var utils = {${entries.join(',')}};` + source.slice(end + 'require = fn;'.length), loader:'js'};
    });
  }};
};
