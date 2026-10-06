const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const bundle = fs.readFileSync(path.resolve(__dirname, '../../../resources/puppeteer.js'), 'utf8');

test('host polyfills preserve native URL, encoding and stream constructors', () => {
  const context = vm.createContext({URL, URLSearchParams, TextEncoder, TextDecoder, ReadableStream, php: {now: () => 0}, quickjs: {postMessage() {}}});
  vm.runInContext(bundle, context);
  assert.equal(context.URL, URL);
  assert.equal(context.URLSearchParams, URLSearchParams);
  assert.equal(context.TextEncoder, TextEncoder);
  assert.equal(context.TextDecoder, TextDecoder);
  assert.equal(context.ReadableStream, ReadableStream);
});

test('host services preserve native crypto and fetch implementations', () => {
  const crypto = {getRandomValues() { throw new Error('Native crypto must not be called during installation'); }};
  const fetch = () => { throw new Error('Native fetch must not be called during installation'); };
  const context = vm.createContext({crypto, fetch, php: {now: () => 0}, quickjs: {postMessage() {}}});
  vm.runInContext(bundle, context);
  assert.equal(context.crypto, crypto);
  assert.equal(context.fetch, fetch);
});
