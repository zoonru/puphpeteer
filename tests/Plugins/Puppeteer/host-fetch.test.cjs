const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const bundle = fs.readFileSync(path.resolve(__dirname, '../../../resources/puppeteer.js'), 'utf8');

function environment() {
  const calls = [];
  let context;
  let reads = 0;
  context = vm.createContext({php: {now: () => 0}, quickjs: {postMessage([kind, payload]) {
    calls.push({kind, ...payload});
    if (kind !== 'http') return;
    const value = payload.operation === 'start'
      ? {status: 200, statusText: 'OK', headers: [['content-type', 'text/plain']], url: 'http://localhost/', redirected: false, noBody: false}
      : payload.operation === 'read' && reads++ === 0 ? new (vm.runInContext('Uint8Array', context))([255, 65, 66, 67]) : null;
    queueMicrotask(() => context.__quickjsDispatch('callbackResult', {id: payload.id, value}));
  }}});
  vm.runInContext(bundle, context);
  return {context, calls};
}

test('host fetch performs no body reads before consumption and cancel closes the host response', async () => {
  const {context, calls} = environment();
  const response = await context.fetch('http://localhost/');
  await Promise.resolve();
  assert.equal(response.bodyUsed, false);
  assert.equal(calls.filter(call => call.operation === 'read').length, 0);
  await response.body.cancel();
  assert.equal(response.bodyUsed, true);
  assert.equal(calls.filter(call => call.operation === 'close').length, 1);
});

test('cloned responses consume independent bodies and enforce bodyUsed', async () => {
  const {context} = environment();
  const response = await context.fetch('http://localhost/');
  const copy = response.clone();
  assert.equal(response.bodyUsed, false);
  assert.equal(copy.bodyUsed, false);
  assert.deepEqual(await Promise.all([response.text(), copy.text()]), ['ABC', 'ABC']);
  assert.equal(response.bodyUsed, true);
  await assert.rejects(() => response.text(), {name: 'TypeError'});
  assert.throws(() => copy.clone(), {name: 'TypeError'});
});

test('Request, Response and Headers validate bodies and preserve values', async () => {
  const {context} = environment();
  const result = await vm.runInContext(`(async () => {
    const request = new Request('http://localhost/', {method: 'post', body: 'Я😀', headers: {'X-Test': ' a '}});
    const copy = request.clone();
    const first = await request.text(), second = await copy.text();
    const json = Response.json({value: 42});
    const value = await json.json();
    return [first, second, request.method, request.headers.get('x-test'), value.value,
      json.headers.get('content-type'), Response.error().status, Response.redirect('http://localhost/').status];
  })()`, context);
  assert.deepEqual(Array.from(result), ['Я😀', 'Я😀', 'POST', 'a', 42, 'application/json', 0, 302]);
  for (const source of [
    `new Request('file:///tmp/a')`, `new Request('http://user:password@localhost/')`,
    `new Request('http://localhost/', {body: 'a'})`, `new Headers({'X-Test': 'a\\r\\nb'})`,
    `new Response('body', {status: 204})`, `new Request('http://localhost/', {redirect: 'bad'})`,
  ]) assert.throws(() => vm.runInContext(source, context));
});

test('abort after headers errors unread response bodies with the original reason', async () => {
  const {context, calls} = environment();
  const controller = new context.AbortController();
  const response = await context.fetch('http://localhost/', {signal: controller.signal});
  const reason = {message: 'stop'};
  controller.abort(reason);
  await assert.rejects(() => response.text(), error => error === reason);
  assert.equal(calls.filter(call => call.kind === 'httpCancel').length, 1);
});

test('byte bodies copy the selected view and support cloning, empty reads and cancellation', async () => {
  const {context} = environment();
  const result = await vm.runInContext(`(async () => {
    const bytes = new Uint8Array([0, 65, 66, 0]);
    const response = new Response(new DataView(bytes.buffer, 1, 2));
    bytes.fill(0);
    const copy = response.clone();
    const values = await Promise.all([response.text(), copy.text()]);
    const empty = new Response(new ArrayBuffer(0));
    values.push((await empty.arrayBuffer()).byteLength, empty.bodyUsed);
    const canceled = new Response(new Uint8Array([65]));
    await canceled.body.cancel();
    values.push(canceled.bodyUsed);
    return values;
  })()`, context);
  assert.deepEqual(Array.from(result), ['AB', 'AB', 0, true, true]);
});

test('Request normalizes standard methods and preserves custom method casing', () => {
  const {context} = environment();
  assert.equal(vm.runInContext(`new Request('http://localhost/', {method: 'pAtCh'}).method`, context), 'pAtCh');
  for (const method of ['get', 'head', 'trace']) {
    assert.throws(() => vm.runInContext(`new Request('http://localhost/', {method: '${method}', body: 'a'})`, context), {name: 'TypeError'});
  }
});

test('Headers handles prototype names, duplicates, empty values and ByteString coercion', () => {
  const {context} = environment();
  const result = vm.runInContext(`(() => {
    const headers = new Headers([['constructor', 'value'], ['__proto__', 'safe'], ['x-empty', ''], ['x-repeat', ' a '], ['X-Repeat', 'b'], ['set-cookie', 'a=1'], ['set-cookie', 'b=2']]);
    headers.set('x-number', 42);
    return [headers.get('constructor'), headers.get('__proto__'), headers.has('x-empty'), headers.get('x-empty'),
      headers.get('x-repeat'), headers.getSetCookie().join('|'), headers.get('x-number'), new Headers().get('constructor')];
  })()`, context);
  assert.deepEqual(Array.from(result), ['value', 'safe', true, '', 'a, b', 'a=1|b=2', '42', null]);
  assert.throws(() => vm.runInContext(`new Headers([['x', 'Я']])`, context), {name: 'TypeError'});
  assert.throws(() => vm.runInContext(`new Headers([['x']])`, context), {name: 'TypeError'});
});

test('bodyUsed changes on the first reader read and completed requests detach abort listeners', async () => {
  const {context, calls} = environment();
  const local = new context.Response('body');
  const reader = local.body.getReader();
  assert.equal(local.bodyUsed, false);
  const pending = reader.read();
  assert.equal(local.bodyUsed, true);
  await pending;
  await reader.cancel();
  const controller = new context.AbortController();
  const response = await context.fetch('http://localhost/', {signal: controller.signal});
  assert.equal(await response.text(), 'ABC');
  controller.abort();
  assert.equal(calls.filter(call => call.kind === 'httpCancel').length, 0);
});

test('closing the PHP transport errors unconsumed fetch bodies', async () => {
  const {context} = environment();
  const response = await context.fetch('http://localhost/');
  context.__quickjsDispatch('closed', null);
  await assert.rejects(() => response.text(), /PHP transport closed/);
});
