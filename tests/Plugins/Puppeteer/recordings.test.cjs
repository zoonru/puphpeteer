const {test, before} = require('node:test');
const assert = require('node:assert/strict');
const {build} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');
const {ReadableStream} = require('web-streams-polyfill');
let Recordings, ScreenRecording;

before(async () => {
  const result = await build({stdin: {contents: `export {Recordings} from './js/recordings.js'; export {ScreenRecording} from 'puppeteer-core/lib/puppeteer/api/ScreenRecording.js';`, resolveDir: path.resolve(__dirname, '../../..')}, bundle: true, platform: 'node', format: 'cjs', write: false});
  const context = {module: {exports: {}}, ReadableStream, setTimeout, atob};
  vm.runInNewContext(result.outputFiles[0].text, context);
  ({Recordings, ScreenRecording} = context.module.exports);
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
  const recordings = new Recordings(() => assert.fail('No file requested'));
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

test('writes file chunks as they arrive and waits for them in stop', async () => {
  const writes = [];
  const {page, calls} = fixture([{data: 'AQ==', base64Encoded: true, eof: false}, {data: 'Ag==', base64Encoded: true, eof: false}, {eof: true}]);
  const recordings = new Recordings(async (path, overwrite) => {
    assert.equal(path, 'video.mp4'); assert.equal(overwrite, false);
    return {async writeFile(bytes) { writes.push([...bytes]); }, async close() { writes.push('closed'); }};
  });
  const recording = await recordings.start(page, {path: 'video.mp4', overwrite: false});
  await recordings.stop(recording);
  assert.deepEqual(writes, [[1], [2], 'closed']);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
});

test('file-open failure stops recording and closes its CDP handle', async () => {
  const {page, calls} = fixture();
  const recordings = new Recordings(async () => { throw new Error('file exists'); });
  await assert.rejects(recordings.start(page, {path: 'video.mp4'}), /file exists/);
  assert.equal(calls.filter(([method]) => method === 'Page.stopScreenRecording').length, 1);
  assert.equal(calls.filter(([method]) => method === 'IO.close').length, 1);
});

test('file write failure is returned by stop', async () => {
  let closed = false;
  const {page} = fixture([{data: 'AQ==', base64Encoded: true, eof: false}]);
  const recordings = new Recordings(async () => ({async writeFile() { throw new Error('disk full'); }, async close() { closed = true; }}));
  const recording = await recordings.start(page, {path: 'video.mp4'});
  await assert.rejects(recordings.stop(recording), /disk full/);
  assert.equal(closed, true);
});

test('validates options before starting Chrome or opening a file', async () => {
  const {page, calls} = fixture();
  const recordings = new Recordings(() => assert.fail('Must not open file'));
  await assert.rejects(recordings.start(page, {path: 'existing.mp4', frameRate: 0}), /frameRate/);
  assert.deepEqual(calls, []);
});
