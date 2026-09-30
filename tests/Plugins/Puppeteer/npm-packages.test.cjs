const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const zlib = require('node:zlib');
const {webcrypto} = require('node:crypto');
const {buildSync} = require('esbuild');

const installed = path.resolve(__dirname, '../../../node_modules');

function resolveFile(candidate) {
  for (const file of [candidate, `${candidate}.js`, `${candidate}.json`, `${candidate}.cjs`]) {
    if (fs.existsSync(file) && fs.statSync(file).isFile()) return fs.realpathSync(file);
  }
  if (fs.existsSync(candidate) && fs.statSync(candidate).isDirectory()) {
    const manifest = path.join(candidate, 'package.json');
    if (fs.existsSync(manifest)) {
      const main = JSON.parse(fs.readFileSync(manifest, 'utf8')).main;
      if (main) return resolveFile(path.join(candidate, main));
    }
    return resolveFile(path.join(candidate, 'index.js'));
  }
  throw new Error(`Cannot resolve npm file: ${candidate}`);
}

function moduleResolver(root) {
  const base = fs.realpathSync(root);
  const scoped = filename => {
    const resolved = resolveFile(filename);
    if (resolved !== base && !resolved.startsWith(`${base}${path.sep}`)) {
      throw new Error(`Module outside test root: ${resolved}`);
    }
    return resolved;
  };
  return (specifier, parentPath) => {
    if (path.isAbsolute(specifier)) {
      if (parentPath !== null) throw new Error(`Absolute package import denied: ${specifier}`);
      return scoped(specifier);
    }
    if (specifier.startsWith('.')) return scoped(path.resolve(path.dirname(parentPath), specifier));
    const segments = specifier.split('/');
    const packageName = specifier.startsWith('@') ? segments.splice(0, 2).join('/') : segments.shift();
    return scoped(path.join(base, packageName, ...segments));
  };
}

function runtime(root) {
  const source = buildSync({
    stdin: {contents: `export {installNpmLoader} from './js/npm-loader.js'; export {createNodeCompat} from './js/node-compat.js'; export {adaptAdmZip} from './js/adm-zip-adapter.js';`, resolveDir: path.resolve(__dirname, '../../..')},
    bundle: true, platform: 'neutral', format: 'cjs', write: false,
  }).outputFiles[0].text;
  const context = {module: {exports: {}}, TextEncoder, TextDecoder, crypto: webcrypto, console};
  vm.runInNewContext(source, context);
  const {installNpmLoader, createNodeCompat, adaptAdmZip} = context.module.exports;
  const builtins = createNodeCompat({
    root,
    exists: filename => fs.existsSync(filename),
    readBinary: filename => fs.readFileSync(filename),
    inflateRaw: (bytes, limit) => zlib.inflateRawSync(Buffer.from(bytes), {maxOutputLength: limit ?? undefined}),
    deflateRaw: bytes => zlib.deflateRawSync(Buffer.from(bytes)),
    decodeUtf8: bytes => new TextDecoder('utf-8', {fatal: true}).decode(bytes),
    crc32: (bytes, value) => zlib.crc32(Buffer.from(bytes), value),
  });
  return installNpmLoader({
    resolve: moduleResolver(root),
    readSource: filename => fs.readFileSync(filename, 'utf8'),
    builtins,
    afterLoad: (filename, exports) => adaptAdmZip(filename, exports, builtins),
  });
}

test('unmodified fingerprint-injector creates an injected page', async () => {
  const npm = runtime(installed);
  const {newInjectedPage} = npm.requireModule(path.join(installed, 'fingerprint-injector/index.js'));
  assert.equal(typeof newInjectedPage, 'function');
  const calls = [];
  const page = {
    setUserAgent: async value => calls.push(['userAgent', value]),
    browser: () => ({version: async () => 'Chrome/125'}),
    target: () => ({createCDPSession: async () => ({send: async (name, value) => calls.push([name, value])})}),
    setExtraHTTPHeaders: async value => calls.push(['headers', value]),
    emulateMediaFeatures: async value => calls.push(['media', value]),
    evaluateOnNewDocument: async value => calls.push(['script', value]),
  };
  const browser = {newPage: async () => page};
  const result = await newInjectedPage(browser, {fingerprintOptions: {devices: ['mobile'], operatingSystems: ['ios']}});
  assert.strictEqual(result, page);
  assert(calls.some(([name]) => name === 'script'));
  assert(calls.some(([name]) => name === 'Page.setDeviceMetricsOverride'));
});

test('unmodified stealth loads its dynamically selected evasion', () => {
  const npm = runtime(installed);
  const stealth = npm.requireModule(path.join(installed, 'puppeteer-extra-plugin-stealth/index.js'));
  assert.equal(typeof stealth, 'function');
  const plugin = stealth();
  assert.equal(plugin.name, 'stealth');
  const dependency = [...plugin.dependencies][0];
  assert.match(dependency, /^stealth\/evasions\//);
  const evasion = npm.requireModule(`puppeteer-extra-plugin-${dependency}`, path.join(installed, 'puppeteer-extra-plugin-stealth/index.js'));
  assert.equal(typeof evasion, 'function');
  assert.match(evasion().name, /^stealth\/evasions\//);
});

test('adm-zip uses the host CRC32 and still rejects a bad checksum', () => {
  const npm = runtime(installed);
  const utils = npm.requireModule('adm-zip/util/utils');
  assert.strictEqual(utils.crc32, npm.requireModule('node:zlib').crc32);

  const file = path.join(installed, 'fingerprint-generator/data_files/fingerprint-network-definition.zip');
  const archive = Buffer.from(fs.readFileSync(file));
  assert.equal(archive.readUInt32LE(0), 0x04034b50);
  const AdmZip = npm.requireModule('adm-zip');
  const CompatBuffer = npm.requireModule('node:buffer').Buffer;
  assert.equal(new AdmZip(CompatBuffer.from(archive)).getEntries()[0].getData().length, 13008418);
  archive.writeUInt32LE(0, 14); // Corrupt the local ZIP header's CRC32.
  assert.throws(() => new AdmZip(CompatBuffer.from(archive)).getEntries()[0].getData(), /CRC/i);
});
