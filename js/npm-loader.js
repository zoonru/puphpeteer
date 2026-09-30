/** Install a CommonJS loader whose filesystem and resolution are owned by the host. */
export function installNpmLoader({
  resolve, readSource, builtins = {},
  afterLoad = () => {},
  process: processShim = {platform: 'linux', versions: {node: '22.0.0'}, env: {}, stderr: {fd: 2, write() {}}},
  global: providedGlobal = {},
}) {
  if (typeof resolve !== 'function' || typeof readSource !== 'function' || typeof afterLoad !== 'function') {
    throw new TypeError('npm loader requires resolve, readSource and afterLoad callbacks');
  }

  const cache = new Map();
  const BufferShim = builtins.buffer?.Buffer;
  const nodeGlobal = {...providedGlobal, Buffer: BufferShim, process: processShim};
  nodeGlobal.global = nodeGlobal;
  const hasBuiltin = name => Object.hasOwn(builtins, name);
  const builtin = specifier => {
    if (hasBuiltin(specifier)) return specifier;
    if (specifier.startsWith('node:')) {
      const name = specifier.slice(5);
      if (hasBuiltin(name)) return name;
      throw new Error(`Unsupported Node builtin: ${specifier}`);
    }
    return null;
  };

  function requireModule(specifier, parentPath = null) {
    if (typeof specifier !== 'string' || !specifier) throw new TypeError('Module specifier must be a nonempty string');
    const builtinName = builtin(specifier);
    if (builtinName !== null) return builtins[builtinName];

    const filename = resolve(specifier, parentPath);
    if (typeof filename !== 'string' || !filename.startsWith('/')) {
      throw new TypeError(`Resolver did not return an absolute path for ${specifier}`);
    }
    if (cache.has(filename)) return cache.get(filename).exports;

    const module = {id: filename, filename, exports: {}, loaded: false};
    cache.set(filename, module); // Required before evaluation to support CommonJS cycles.
    try {
      const source = readSource(filename);
      if (typeof source !== 'string') throw new TypeError(`Source is not a string: ${filename}`);
      if (filename.endsWith('.json')) {
        module.exports = JSON.parse(source);
      } else {
        const dirname = filename.slice(0, filename.lastIndexOf('/')) || '/';
        const localRequire = name => requireModule(name, filename);
        // A URI-escaped sourceURL keeps stack traces useful without allowing a
        // filename containing a newline to inject another source comment.
        const sourceURL = encodeURI(filename).replace(/[?#]/g, character => encodeURIComponent(character));
        const run = new Function('require', 'module', 'exports', '__filename', '__dirname', 'Buffer', 'process', 'global', `${source}\n//# sourceURL=${sourceURL}`);
        run.call(module.exports, localRequire, module, module.exports, filename, dirname, BufferShim, processShim, nodeGlobal);
      }
      afterLoad(filename, module.exports);
      module.loaded = true;
      return module.exports;
    } catch (error) {
      cache.delete(filename);
      throw error;
    }
  }

  return {requireModule};
}
