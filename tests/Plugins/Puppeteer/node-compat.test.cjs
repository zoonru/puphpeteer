const {test} = require('node:test');
const assert = require('node:assert/strict');
const {buildSync} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');
const zlib = require('node:zlib');

const source = buildSync({entryPoints: ['js/node-compat.js'], bundle: true, platform: 'browser', format: 'iife', globalName: 'compat', write: false}).outputFiles[0].text;
const context = vm.createContext({Uint8Array, ArrayBuffer, DataView, TextEncoder, BigInt});
vm.runInContext(source, context);
const {createNodeCompat, CompatBuffer} = context.compat;

function builtins(files = {}) {
  const calls = [];
  const modules = createNodeCompat({
    root: '/app',
    readBinary(file) { calls.push(['read', file]); if (!(file in files)) throw new Error('ENOENT'); return files[file]; },
    exists(file) { calls.push(['exists', file]); return file in files; },
    inflateRaw(value, limit) { calls.push(['inflate', limit]); return zlib.inflateRawSync(Buffer.from(value), {maxOutputLength: limit ?? undefined}); },
    deflateRaw(value) { calls.push(['deflate']); return zlib.deflateRawSync(Buffer.from(value)); },
    decodeUtf8(value) { return new TextDecoder('utf-8', {fatal: true}).decode(value); },
    crc32(value, seed) { calls.push(['crc32', seed]); return zlib.crc32(Buffer.from(value), seed); },
  });
  return {modules, calls};
}

test('read-only fs returns Buffer or decoded text and confines paths', () => {
  const {modules, calls} = builtins({'/app/lib/data.json': Buffer.from('{"ok":true}')});
  assert.equal(modules.fs, modules['node:fs']);
  assert.equal(modules.fs.existsSync('/app/lib/data.json'), true);
  assert.equal(modules.fs.readFileSync('lib/data.json', 'utf8'), '{"ok":true}');
  assert.equal(modules.fs.readFileSync('/app/lib/data.json').toString(), '{"ok":true}');
  assert.deepEqual(calls, [['exists', '/app/lib/data.json'], ['read', '/app/lib/data.json'], ['read', '/app/lib/data.json']]);
  assert.throws(() => modules.fs.readFileSync('../secret'), /outside plugin root/);
  assert.throws(() => modules.fs.existsSync('/application/secret'), /outside plugin root/);
  assert.equal(modules.fs.writeFileSync, undefined);
});

test('Buffer supports byte views and ZIP header numeric operations', () => {
  const buf = CompatBuffer.alloc(8);
  buf.writeUInt16LE(0x1234, 1);
  buf.writeUInt32LE(0x89abcdef, 3);
  assert.equal(buf.readUInt16LE(1), 0x1234);
  assert.equal(buf.readUInt32LE(3), 0x89abcdef);
  const view = buf.slice(1, 3);
  view[0] = 7;
  assert.equal(buf[1], 7);
  assert.equal(CompatBuffer.from('é').toString('hex'), 'c3a9');
  assert.equal(CompatBuffer.from('AP8=', 'base64').toString('hex'), '00ff');
  assert.equal(CompatBuffer.from([0, 255]).toString('base64'), 'AP8=');
  const wide = CompatBuffer.alloc(8);
  wide.writeBigUInt64LE(0x100000001n);
  assert.equal(wide.readBigUInt64LE(), 0x100000001n);
});

test('path operations and raw ZIP compression callbacks', () => {
  const {modules, calls} = builtins();
  assert.equal(modules.path.resolve('/app/lib', '../data/file.zip'), '/app/data/file.zip');
  assert.equal(modules.path.relative('/app/lib', '/app/data/file.zip'), '../data/file.zip');
  assert.equal(modules.path.posix.basename('/app/data/file.zip'), 'file.zip');
  const compressed = modules.zlib.deflateRawSync(CompatBuffer.from('payload'));
  assert.equal(modules.zlib.inflateRawSync(compressed, {maxOutputLength: 7}).toString(), 'payload');
  assert.deepEqual(calls, [['deflate'], ['inflate', 7]]);
  assert.throws(() => modules.zlib.inflateRawSync(compressed, {maxOutputLength: 3}), /maxOutputLength|larger/);
});

test('Node zlib CRC32 supports strings, byte views and a previous checksum', () => {
  const {modules, calls} = builtins();
  assert.equal(modules.zlib, modules['node:zlib']);
  assert.equal(modules.zlib.crc32('hello'), 907060870);
  assert.equal(modules.zlib.crc32('world', 907060870), 4192936109);
  const bytes = CompatBuffer.from([0, 1, 2, 255]);
  assert.equal(modules.zlib.crc32(new DataView(bytes.buffer, 1, 2)), zlib.crc32(Buffer.from([1, 2])));
  assert.deepEqual(calls, [['crc32', 0], ['crc32', 907060870], ['crc32', 0]]);
  assert.throws(() => modules.zlib.crc32(bytes, -1), /CRC32 seed/);
});

test('unsupported compression callback fails explicitly', () => {
  const modules = createNodeCompat({root: '/app', readBinary() {return new Uint8Array();}, exists() {return false;}, decodeUtf8() {return '';}, crc32() {return 0;}});
  assert.throws(() => modules.zlib.inflateRawSync(CompatBuffer.alloc(0)), /Unsupported Node API/);
});

test('large base64 assets decode without regexp stack overflow', () => {
  const bytes = CompatBuffer.from('AAAA'.repeat(1024 * 1024), 'base64');
  assert.equal(bytes.length, 3 * 1024 * 1024);
  assert.throws(() => CompatBuffer.from('A==A', 'base64'), /Invalid base64/);
});

test('large inflated UTF-8 buffers decode correctly', () => {
  const {modules, calls} = builtins();
  const data = 'A'.repeat(65536);
  const compressed = zlib.deflateRawSync(Buffer.from(data));
  assert.equal(modules.zlib.inflateRawSync(compressed).toString(), data);
  assert.deepEqual(calls, [['inflate', null]]);
});

test('UTF-8 decoding rejects invalid bytes', () => {
  builtins();
  assert.throws(() => CompatBuffer.from([0xc3, 0x28]).toString(), /not valid|invalid/i);
});

test('debug terminal builtins stay inert', () => {
  const {modules} = builtins();
  assert.equal(modules.tty.isatty(2), false);
  assert.equal(modules.util.deprecate(() => 7, 'unused')(), 7);
  assert.equal(modules['node:tty'], modules.tty);
});

test('Node crypto randomFillSync fills only the requested byte view and respects the Web Crypto quota', () => {
  const sizes = [];
  const randomContext = vm.createContext({Uint8Array, ArrayBuffer, DataView, crypto: {getRandomValues(bytes) {
    sizes.push(bytes.length);
    assert.ok(bytes.length <= 65536);
    bytes.fill(0x7a);
    return bytes;
  }}});
  vm.runInContext(source, randomContext);
  const crypto = randomContext.compat.createNodeCompat({root: '/app', readBinary() { assert.fail('Unexpected filesystem read'); }, exists() { return false; }, decodeUtf8() { assert.fail('Unexpected decoding'); }, crc32() { assert.fail('Unexpected CRC32'); }}).crypto;
  const bytes = new Uint8Array(70010).fill(0x55);
  const view = new DataView(bytes.buffer, 5, 70000);
  assert.equal(crypto.randomFillSync(view, 2, 69990), view);
  assert.deepEqual(sizes, [65536, 4454]);
  assert.ok(bytes.subarray(0, 7).every(byte => byte === 0x55));
  assert.ok(bytes.subarray(7, 69997).every(byte => byte === 0x7a));
  assert.ok(bytes.subarray(69997).every(byte => byte === 0x55));
  assert.throws(() => crypto.randomFillSync(view, -1), {name: 'RangeError'});
});
