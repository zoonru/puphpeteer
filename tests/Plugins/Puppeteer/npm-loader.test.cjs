const {test} = require('node:test');
const assert = require('node:assert/strict');
const {buildSync} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');

function loader(files, builtins = {}, options = {}) {
  const source = buildSync({
    stdin: {contents: `export {installNpmLoader} from './js/npm-loader.js';`, resolveDir: path.resolve(__dirname, '../../..')},
    bundle: true, platform: 'neutral', format: 'cjs', write: false,
  }).outputFiles[0].text;
  const context = {module: {exports: {}}};
  vm.runInNewContext(source, context);
  const resolved = [];
  const read = [];
  const api = context.module.exports.installNpmLoader({
    resolve(specifier, parentPath) {
      resolved.push([specifier, parentPath]);
      if (specifier.startsWith('/') && parentPath !== null) throw new Error('Absolute package import denied');
      const filename = specifier.startsWith('/') ? specifier : specifier.startsWith('.')
        ? path.posix.resolve(path.posix.dirname(parentPath), specifier)
        : `/app/node_modules/${specifier}/index.js`;
      if (!Object.hasOwn(files, filename)) throw new Error(`Cannot resolve ${specifier} from ${parentPath}`);
      return filename;
    },
    readSource(filename) { read.push(filename); return files[filename]; },
    builtins,
    ...options,
  });
  return {api, resolved, read};
}

test('loads relative and package modules, JSON, and node: builtins with one cached instance', () => {
  const {api, resolved, read} = loader({
    '/app/entry.js': `const a = require('widget'); const b = require('widget');
      module.exports = [a, b, require('./config.json'), require('node:path').marker, __filename, __dirname];`,
    '/app/node_modules/widget/index.js': `module.exports = require('./value.js');`,
    '/app/node_modules/widget/value.js': `module.exports = {count: 1};`,
    '/app/config.json': `{"enabled":true}`,
  }, {path: {marker: 'builtin'}});
  const result = api.requireModule('/app/entry.js');
  assert.strictEqual(result[0], result[1]);
  assert.equal(result[0].count, 1);
  assert.equal(result[2].enabled, true);
  assert.deepEqual(Array.from(result.slice(3)), ['builtin', '/app/entry.js', '/app']);
  assert.equal(read.length, 4);
  assert.deepEqual(resolved[1], ['widget', '/app/entry.js']);
  assert.strictEqual(api.requireModule('/app/entry.js'), result);
});

test('CommonJS cycles expose partially populated exports', () => {
  const {api} = loader({
    '/app/a.js': `exports.name = 'a'; exports.other = require('./b.js').name;`,
    '/app/b.js': `exports.name = 'b'; exports.other = require('./a.js').name;`,
  });
  assert.deepEqual({...api.requireModule('/app/a.js')}, {name: 'a', other: 'b'});
  assert.deepEqual({...api.requireModule('/app/b.js')}, {name: 'b', other: 'a'});
});

test('a failed module is evicted and unsupported imports fail clearly', () => {
  const files = {'/app/fail.js': `throw new Error('first failure');`};
  const {api, read} = loader(files);
  assert.throws(() => api.requireModule('/app/fail.js'), /first failure/);
  assert.throws(() => api.requireModule('/app/fail.js'), /first failure/);
  assert.equal(read.length, 2);
  assert.throws(() => api.requireModule('node:crypto'), /Unsupported Node builtin: node:crypto/);
  assert.throws(() => api.requireModule('missing', '/app/fail.js'), /Cannot resolve missing/);
  assert.throws(() => api.requireModule('/app/fail.js', '/app/other.js'), /Absolute package import denied/);
});

test('sourceURL identifies the module in stack traces', () => {
  const {api} = loader({'/app/throw.js': `throw new Error('boom');`});
  assert.throws(() => api.requireModule('/app/throw.js'), error => {
    assert.match(error.stack, /\/app\/throw\.js/);
    return true;
  });
});

test('the VM has no ambient Node require', () => {
  const {api} = loader({'/app/entry.js': `module.exports = typeof globalThis.require;`});
  assert.equal(api.requireModule('/app/entry.js'), 'undefined');
});

test('executes a standard esbuild platform=node CommonJS entry', () => {
  const entry = buildSync({
    stdin: {contents: `import {readFileSync} from 'node:fs'; export const value = readFileSync('/data.txt', 'utf8');`},
    bundle: true, platform: 'node', format: 'cjs', write: false,
  }).outputFiles[0].text;
  const {api} = loader({'/app/entry.cjs': entry}, {fs: {readFileSync: () => 'loaded'}});
  assert.equal(api.requireModule('/app/entry.cjs').value, 'loaded');
});

test('provides Node globals to modules without mutating the QuickJS global object', () => {
  const BufferShim = class BufferShim {};
  const processShim = {platform: 'darwin', versions: {node: 'custom'}};
  const {api} = loader({
    '/app/entry.js': `module.exports = {
      buffer: Buffer === require('node:buffer').Buffer,
      platform: process.platform,
      shared: global.Buffer === Buffer && global.process === process && global.global === global,
      ambient: typeof globalThis.Buffer + ':' + typeof globalThis.process,
    };`,
  }, {buffer: {Buffer: BufferShim}}, {process: processShim});
  assert.deepEqual({...api.requireModule('/app/entry.js')}, {
    buffer: true, platform: 'darwin', shared: true, ambient: 'undefined:undefined',
  });
});

test('default process shim supplies the surface needed by debug', () => {
  const {api} = loader({'/app/entry.js': `module.exports = {
    env: Object.keys(process.env).length,
    fd: process.stderr.fd,
    write: process.stderr.write('ignored'),
  };`});
  assert.deepEqual({...api.requireModule('/app/entry.js')}, {env: 0, fd: 2, write: undefined});
});
