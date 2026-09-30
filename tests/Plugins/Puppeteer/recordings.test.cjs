const {test, before} = require('node:test');
const assert = require('node:assert/strict');
const {build} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');
const {ReadableStream} = require('web-streams-polyfill');
let Recordings, ScreenRecording, GuestReadableStreams;

before(async () => {
  const result = await build({stdin: {contents: `export {Recordings} from './js/recordings.js'; export {GuestReadableStreams} from './js/readable-streams.js'; export {ScreenRecording} from 'puppeteer-core/lib/puppeteer/api/ScreenRecording.js';`, resolveDir: path.resolve(__dirname, '../../..')}, bundle: true, platform: 'node', format: 'cjs', write: false});
  const context = {module: {exports: {}}, ReadableStream, setTimeout, atob};
  vm.runInNewContext(result.outputFiles[0].text, context);
  ({Recordings, ScreenRecording, GuestReadableStreams} = context.module.exports);
});

function fixture(results = []) {
  const calls = [];
  const client = {async send(method, params = {}) {
    calls.push([method, {...params}]);
    if (method === 'Page.startScreenRecording') return {stream: 'video'};
    if (method === 'IO.read') {
      const result = results.shift();
      if (result instanceof Error) throw result;
      return result ?? {eof: true};
    }
    return {};
  }};
  const page = {
    mainFrame: () => ({client}),
    createScreenRecording(options) { this.options = options; return new ScreenRecording(this, options); },
  };
  return {page, calls};
}

test('reads live chunks before stop and applies stream backpressure', async () => {
  const {page, calls} = fixture([new Error('Protocol error (IO.read): Read failed'), {data: 'AP8=', base64Encoded: true, eof: true}, {data: 'Kg==', base64Encoded: true, eof: true}]);
  const recordings = new Recordings();
  const recording = await recordings.start(page, {fps: 20});
  const reader = recordings.readable(recording).getReader();
  assert.deepEqual([...(await reader.read()).value], [0, 255]);
  assert.equal(calls.some(([method]) => method === 'Page.stopScreenRecording'), false);
  await recordings.stop(recording);
  assert.deepEqual([...(await reader.read()).value], [42]);
  assert.equal((await reader.read()).done, true);
  await recordings.stop(recording);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
  assert.deepEqual(calls[0], ['Page.startScreenRecording', {audio: undefined, maxWidth: undefined, maxHeight: undefined, frameRate: 20}]);
});

test('validates options before starting Chrome', async () => {
  const {page, calls} = fixture();
  const recordings = new Recordings();
  await assert.rejects(recordings.start(page, {frameRate: 0}), /frameRate/);
  assert.deepEqual(calls, []);
});

test('an unread stream does not read or retain video chunks', async () => {
  const {page, calls} = fixture([{data: Buffer.alloc(65536).toString('base64'), base64Encoded: true}]);
  const recordings = new Recordings();
  const recording = await recordings.start(page);
  await new Promise(resolve => setTimeout(resolve, 10));
  assert.equal(calls.filter(([method]) => method === 'IO.read').length, 0);
  await recordings.discard(recording);
  await recordings.discard(recording);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
  assert.equal(calls.filter(([method]) => method === 'IO.close').length, 1);
});

test('CDP reads request at most 64 KiB', async () => {
  const {page, calls} = fixture([{data: Buffer.alloc(65536, 42).toString('base64'), base64Encoded: true}]);
  const recordings = new Recordings();
  const recording = await recordings.start(page);
  const reader = recordings.readable(recording).getReader();
  assert.equal((await reader.read()).value.length, 65536);
  assert.equal(calls.find(([method]) => method === 'IO.read')[1].size, 65536);
  await reader.cancel();
});

test('read failure stops Chrome and closes the CDP handle', async () => {
  const {page, calls} = fixture([new Error('connection lost')]);
  const recordings = new Recordings();
  const recording = await recordings.start(page);
  await assert.rejects(recordings.readable(recording).getReader().read(), /connection lost/);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
  assert.equal(calls.filter(([method]) => method === 'IO.close').length, 1);
});

test('stop failure closes the CDP handle and remains idempotent', async () => {
  const {page, calls} = fixture();
  const client = page.mainFrame().client;
  const send = client.send;
  client.send = async (method, params) => {
    const result = await send(method, params);
    if (method === 'Page.stopScreenRecording') throw new Error('connection lost');
    return result;
  };
  const recordings = new Recordings();
  const recording = await recordings.start(page);
  await assert.rejects(recordings.stop(recording), /connection lost/);
  await assert.rejects(recordings.stop(recording), /connection lost/);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
  assert.equal(calls.filter(([method]) => method === 'IO.close').length, 1);
});

test('PHP boundary receives original CDP data without JS base64 decoding', async () => {
  const data = Buffer.from(Array.from({length: 65536}, (_, i) => i % 256)).toString('base64');
  const {page, calls} = fixture([{data, base64Encoded: true}, {data: '', eof: false}, {data: 'plain text', base64Encoded: false}]);
  const recordings = new Recordings();
  const recording = await recordings.start(page);
  const streams = new GuestReadableStreams();
  const {id} = streams.encode(recordings.readable(recording));
  assert.equal(calls.filter(([method]) => method === 'IO.read').length, 0);
  const chunk = await streams.read(id);
  assert.equal(chunk.data, data);
  assert.equal(chunk.base64Encoded, true);
  assert.equal((await streams.read(id)).data, 'plain text');
  await streams.cancel(id);
  assert.equal(calls.filter(([method]) => method === 'IO.close').length, 1);
});
