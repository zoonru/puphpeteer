const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').resolve(__dirname, '../../../js/abort-controller.js'), 'utf8');

test('QuickJS cancellation reports a stable reason and invokes listeners once', () => {
  const context = vm.createContext({console});
  vm.runInContext(source, context);
  const controller = new context.AbortController();
  let calls = 0;
  const callback = event => { calls++; assert.equal(event.target, controller.signal); };
  controller.signal.addEventListener('abort', callback, {once:true});
  controller.signal.addEventListener('abort', callback);
  const removed = () => assert.fail('Removed abort callback');
  controller.signal.addEventListener('abort', removed);
  controller.signal.removeEventListener('abort', removed);
  const reason = new Error('navigation restarted');
  controller.abort(reason);
  controller.abort(new Error('ignored'));
  assert.equal(controller.signal.aborted, true);
  assert.equal(controller.signal.reason, reason);
  assert.equal(calls, 1);
  assert.throws(() => controller.signal.throwIfAborted(), error => error === reason);
  const fresh = new context.AbortController();
  fresh.signal.throwIfAborted();
  fresh.abort();
  assert.equal(fresh.signal.reason.name, 'AbortError');
});

test('native AbortController is preserved', () => {
  const context = vm.createContext({AbortController});
  vm.runInContext(source, context);
  assert.equal(context.AbortController, AbortController);
});
