const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').resolve(__dirname, '../../../resources/puppeteer.js'), 'utf8');

test('QuickJS cancellation reports a stable reason and invokes listeners once', () => {
  const context = vm.createContext({console, php: {now: () => 0}, quickjs: {postMessage() {}}});
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
  const context = vm.createContext({AbortController, AbortSignal, php: {now: () => 0}, quickjs: {postMessage() {}}});
  vm.runInContext(source, context);
  assert.equal(context.AbortController, AbortController);
});

test('AbortSignal.any accepts iterables, keeps the first reason and cleans source listeners', () => {
  const context = vm.createContext({php: {now: () => 0}, quickjs: {postMessage() {}}});
  vm.runInContext(source, context);
  const first = new context.AbortController();
  const second = new context.AbortController();
  let added = 0;
  let removed = 0;
  for (const signal of [first.signal, second.signal]) {
    const add = signal.addEventListener.bind(signal);
    const remove = signal.removeEventListener.bind(signal);
    signal.addEventListener = (...args) => { added++; add(...args); };
    signal.removeEventListener = (...args) => { removed++; remove(...args); };
  }
  const reason = {why: 'cancel'};
  const combined = context.AbortSignal.any(new Set([first.signal, second.signal]));
  second.abort(reason);
  first.abort('ignored');
  assert.equal(combined.reason, reason);
  assert.equal(added, 2);
  assert.equal(removed, 2);
  added = removed = 0;
  assert.equal(context.AbortSignal.any([first.signal, second.signal]).reason, 'ignored');
  assert.equal(added, 0);
  assert.equal(context.AbortSignal.abort(null).reason, null);
  assert.throws(() => context.AbortSignal.any([{}]), {name: 'TypeError'});
});
