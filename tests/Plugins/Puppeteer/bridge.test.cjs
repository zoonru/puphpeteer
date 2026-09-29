const {test, before} = require('node:test');
const assert = require('node:assert/strict');
const {build} = require('esbuild');
const vm = require('node:vm');
let source;
before(async () => {
  source = (await build({entryPoints: ['js/bridge.js'], bundle: true, platform: 'node', format: 'cjs', write: false})).outputFiles[0].text;
});
const bridge = () => {
  const messages = [];
  const context = {
    module: {exports: {}},
    quickjs: {postMessage(value) { messages.push(value); }},
  };
  context.globalThis = context;
  vm.runInNewContext(source, context);
  return {api: context.module.exports, context, messages};
};

test('async drain sleeps until a native notification', async () => {
  const {api: {emit, drain}, messages} = bridge();
  assert.equal(await drain(() => false), false);
  let ready = false;
  const waiting = drain(() => true).then(value => { ready = true; return value; });
  await Promise.resolve();
  assert.equal(ready, false);
  emit('result', 42);
  assert.equal(await waiting, true);
  assert.equal(JSON.stringify(messages), '[["result",42]]');
  assert.equal(await drain(() => false), false);
});

test('native emit errors leave the consumer waiting for a valid message', async () => {
  const {api: {emit, drain}, context} = bridge();
  const waiting = drain(() => true);
  context.quickjs.postMessage = () => { throw new TypeError('size limit'); };
  assert.throws(() => emit('result', 'large'), /size limit/);
  context.quickjs.postMessage = () => {};
  emit('result', 42);
  assert.equal(await waiting, true);
});

test('driver failures wake a suspended consumer', async () => {
  const {api: {drain, fail}} = bridge();
  const waiting = drain(() => true);
  fail(new Error('driver failed'));
  await assert.rejects(waiting, /driver failed/);
  await assert.rejects(drain(() => true), /driver failed/);
});
