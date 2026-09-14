const {test, before} = require('node:test');
const assert = require('node:assert/strict');
const {build} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');
const {ReadableStream} = require('web-streams-polyfill');
const context = {module: {exports: {}}, ReadableStream, Uint8Array, TextEncoder, atob};
let GuestReadableStreams, getReadableFromProtocolStream;
before(async () => {
const result = await build({stdin: {contents: `export {GuestReadableStreams} from './js/readable-streams.js'; export {getReadableFromProtocolStream} from 'puppeteer-core/lib/puppeteer/common/util.js';`, resolveDir: path.resolve(__dirname, '../..')}, bundle: true, platform: 'node', format: 'cjs', write: false, plugins: [require('../../tools/plugin-protocol-stream.cjs')()]});
const source = result.outputFiles[0].text;
vm.runInNewContext(source, context);
({GuestReadableStreams, getReadableFromProtocolStream} = context.module.exports);
});

test('large chunks are sliced, identity is stable and no eager pulls occur', async () => {
  const streams = new GuestReadableStreams();
  let pulls = 0;
  const stream = new ReadableStream({pull(controller) {
    pulls++;
    controller.enqueue(Uint8Array.from({length: 150000}, (_, i) => i % 256));
    controller.close();
  }}, {highWaterMark: 0});
  const {id} = streams.encode(stream);
  assert.equal(streams.encode(stream).id, id);
  assert.equal(pulls, 0);
  const chunks = [];
  for (;;) { const chunk = await streams.read(id); if (chunk === null) break; assert.ok(chunk.length <= 65536); chunks.push(Buffer.from(chunk)); }
  assert.equal(pulls, 1);
  assert.deepEqual(Buffer.concat(chunks), Buffer.from(Uint8Array.from({length: 150000}, (_, i) => i % 256)));
  assert.equal(stream.locked, false);
  await assert.rejects(streams.read(id), /Unknown/);
});
test('pending read can be cancelled and concurrent reads rejected', async () => {
  const streams = new GuestReadableStreams();
  let cancelled = 0;
  const stream = new ReadableStream({pull() { return new Promise(() => {}); }, cancel() { cancelled++; }}, {highWaterMark: 0});
  const {id} = streams.encode(stream);
  const read = streams.read(id);
  await assert.rejects(streams.read(id), /pending/);
  await streams.cancel(id);
  assert.equal(await read, null);
  await streams.cancel(id);
  assert.equal(cancelled, 1);
  assert.equal(stream.locked, false);
});
test('non-byte streams fail and release their source', async () => {
  const streams = new GuestReadableStreams();
  let cancelled = false;
  const stream = new ReadableStream({pull(c) { c.enqueue({bad: true}); }, cancel() { cancelled = true; }}, {highWaterMark: 0});
  await assert.rejects(streams.read(streams.encode(stream).id), /Uint8Array/);
  assert.equal(cancelled, true);
  assert.equal(stream.locked, false);
});
test('CDP cancellation while IO.read is pending closes handle exactly once', async () => {
  const calls = [];
  let finish;
  const client = {send(method, params) {
    calls.push([method, {...params}]);
    return method === 'IO.read' ? new Promise(resolve => { finish = resolve; }) : Promise.resolve();
  }};
  const reader = getReadableFromProtocolStream(client, 'pdf').getReader();
  const read = reader.read();
  await Promise.resolve();
  await reader.cancel();
  finish({data: 'late data', eof: true});
  assert.equal((await read).done, true);
  assert.deepEqual(calls, [['IO.read', {handle: 'pdf', size: 65536}], ['IO.close', {handle: 'pdf'}]]);
});
test('CDP EOF and read errors close the handle', async () => {
  for (const fail of [false, true]) {
    let closes = 0;
    const client = {async send(method) { if (method === 'IO.close') { closes++; return; } if (fail) throw new Error('read failed'); return {data: 'AP8=', base64Encoded: true, eof: true}; }};
    const reader = getReadableFromProtocolStream(client, 'pdf').getReader();
    if (fail) await assert.rejects(reader.read(), /read failed/);
    else { assert.deepEqual([...(await reader.read()).value], [0, 255]); assert.equal((await reader.read()).done, true); }
    assert.equal(closes, 1);
  }
});
