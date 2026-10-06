const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = vm.createContext({php: {now: () => 0}, quickjs: {postMessage() {}}});
vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../../../resources/puppeteer.js'), 'utf8'), context);
const HostDecoder = context.TextDecoder;

function decodeChunks(Decoder, chunks, options) {
  const decoder = new Decoder('utf-8', options);
  return [...chunks, undefined].map((bytes, index) => {
    try { return decoder.decode(bytes, {stream: index < chunks.length}); }
    catch (error) { return {error: error.name}; }
  });
}

function compare(bytes, splits, options) {
  const chunks = [];
  let offset = 0;
  for (const end of [...splits, bytes.length]) {
    chunks.push(bytes.slice(offset, end));
    offset = end;
  }
  assert.deepEqual(decodeChunks(HostDecoder, chunks, options), decodeChunks(TextDecoder, chunks, options),
    `bytes=${Array.from(bytes)}, splits=${splits}, options=${JSON.stringify(options)}`);
}

test('streamed UTF-8 matches native decoding at every scalar and BOM boundary', () => {
  const bytes = new TextEncoder().encode('\ufeffAЯ€😀\ufeffZ');
  for (const options of [{}, {fatal: true}, {ignoreBOM: true}]) {
    for (let split = 0; split <= bytes.length; split++) compare(bytes, [0, split, split], options);
    compare(bytes, Array.from({length: bytes.length}, (_, index) => index + 1), options);
  }
});

test('malformed UTF-8 and fatal streaming recovery match the native decoder', () => {
  const cases = [
    [0xc2], [0xe2, 0x82], [0xf0, 0x9f, 0x98], [0xc3, 0x28], [0xe2, 0x82, 65],
    [0xe0, 0x80, 0x80], [0xed, 0xa0, 0x80], [0xf0, 0x80, 0x80, 0x80],
    [0xf4, 0x90, 0x80, 0x80], [0xff, 65, 0xef, 0xbb, 0xbf],
    [65, 0xff, 66, 0xe2, 0x82, 0xac], [0xef, 0xbb, 0xbf, 0xff, 0xef, 0xbb, 0xbf],
  ];
  for (const bytes of cases.map(bytes => Uint8Array.from(bytes))) {
    for (const options of [{}, {fatal: true}, {ignoreBOM: true}]) {
      for (let split = 0; split <= bytes.length; split++) compare(bytes, [split], options);
      compare(bytes, Array.from({length: bytes.length}, (_, index) => index + 1), options);
    }
  }
});

test('all two-byte inputs and randomized streams match the native replacement decoder', () => {
  for (let first = 0; first < 256; first++) {
    for (let second = 0; second < 256; second++) {
      const bytes = Uint8Array.of(first, second);
      compare(bytes, []);
      compare(bytes, [1]);
    }
  }
  let seed = 42;
  for (let sample = 0; sample < 4000; sample++) {
    const bytes = Uint8Array.from({length: 3 + sample % 13}, () => {
      seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0;
      return seed >>> 24;
    });
    compare(bytes, [sample % bytes.length], {fatal: sample % 2 === 0});
  }
});

test('final decoding resets BOM state and flushes a partial scalar exactly once', () => {
  for (const Decoder of [HostDecoder, TextDecoder]) {
    const decoder = new Decoder();
    assert.equal(decoder.decode(Uint8Array.of(0xe2, 0x82), {stream: true}), '');
    assert.equal(decoder.decode(), '�');
    assert.equal(decoder.decode(), '');
    const bom = Uint8Array.of(0xef, 0xbb, 0xbf);
    assert.equal(decoder.decode(bom, {stream: true}), '');
    assert.equal(decoder.decode(bom, {stream: true}), '\ufeff');
    assert.equal(decoder.decode(), '');
    assert.equal(decoder.decode(bom), '');
    const fatal = new Decoder('utf-8', {fatal: true});
    assert.equal(fatal.decode(Uint8Array.of(0xf0, 0x9f), {stream: true}), '');
    assert.throws(() => fatal.decode(), {name: 'TypeError'});
    assert.equal(fatal.decode(Uint8Array.of(65)), 'A');
  }
});

test('UTF-8 encoding matches native output for BMP, supplementary scalars and lone surrogates', () => {
  const encoder = new context.TextEncoder();
  const native = new TextEncoder();
  let bmp = '';
  for (let code = 0; code <= 0xffff; code++) bmp += String.fromCharCode(code);
  for (const input of [bmp, 'AЯ€😀', '\ud800', '\udfff', '\ud800A\udfff', '\ud800\ud800\udc00',
    String.fromCodePoint(0x10000, 0x10ffff), '€'.repeat(65536), '😀'.repeat(32768), undefined, null, 42]) {
    assert.deepEqual(Array.from(encoder.encode(input)), Array.from(native.encode(input)));
  }
});

test('encodeInto matches native consumption and leaves incomplete scalars unwritten', () => {
  const encoder = new context.TextEncoder();
  const native = new TextEncoder();
  const HostBytes = vm.runInContext('Uint8Array', context);
  for (const input of ['ASCII', 'Я€😀A', '\ud800A\udfff', '😀😀', null, undefined]) {
    for (let capacity = 0; capacity <= 24; capacity++) {
      const actual = new HostBytes(capacity).fill(0x55);
      const expected = new Uint8Array(capacity).fill(0x55);
      const result = encoder.encodeInto(input, actual);
      const reference = native.encodeInto(String(input), expected);
      assert.deepEqual({read: result.read, written: result.written}, reference);
      assert.deepEqual(Array.from(actual), Array.from(expected));
    }
  }
});
