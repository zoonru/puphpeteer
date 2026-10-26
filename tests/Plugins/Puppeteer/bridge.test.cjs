const {test, before} = require('node:test');
const assert = require('node:assert/strict');
const {build} = require('esbuild');
const vm = require('node:vm');
let source;
before(async () => {
  source = (await build({entryPoints: ['js/bridge.js'], bundle: true, platform: 'node', format: 'cjs', write: false})).outputFiles[0].text;
});
const bridge = () => {
  const messages = [], errors = [];
  const context = {
    module: {exports: {}},
    quickjs: {
      postMessage(value) { messages.push(value); },
      reportError(error) { errors.push(error); },
    },
  };
  vm.runInNewContext(source, context);
  return {api: context.module.exports, context, messages, errors};
};

test('emit forwards messages to the native queue', () => {
  const {api: {emit}, messages} = bridge();
  emit('result', 42);
  assert.equal(JSON.stringify(messages), '[["result",42]]');
});

test('native queue errors reach the caller', () => {
  const {api: {emit}, context} = bridge();
  context.quickjs.postMessage = () => { throw new TypeError('size limit'); };
  assert.throws(() => emit('result', 'large'), /size limit/);
});

test('background failures reach the native error handler unchanged', () => {
  const {api: {fail}, errors} = bridge();
  const error = new Error('driver failed');
  fail(error);
  fail(0);
  assert.deepEqual(errors, [error, 0]);
});
