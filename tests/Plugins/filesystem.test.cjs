const {test} = require('node:test');
const assert = require('node:assert/strict');
const {buildSync} = require('esbuild');
const vm = require('node:vm');
const path = require('node:path');
function environment(request) {
  const source = buildSync({stdin: {contents: `export {installFilesystem} from './js/filesystem.js'; export {environment} from 'puppeteer-core/lib/puppeteer/environment.js';`, resolveDir: path.resolve(__dirname, '../..')}, bundle: true, platform: 'node', format: 'cjs', write: false}).outputFiles[0].text;
  const context = {module: {exports: {}}, Uint8Array, TextDecoder, atob};
  vm.runInNewContext(source, context);
  context.module.exports.installFilesystem(request);
  return context.module.exports.environment.value;
}
test('UTF-8, ASCII and raw bytes preserve upstream readFile contract', async () => {
  const fs = environment(async () => Buffer.from([0xc3, 0xa9, 0, 0xff]).toString('base64'));
  assert.deepEqual([...await fs.readFile('x')], [195, 169, 0, 255]);
  assert.equal(await fs.readFile('x', 'utf8'), 'é\0�');
  assert.equal(await fs.readFile('x', 'ascii'), 'C)\0\x7f');
});
test('file handles append in order, close once and reject writes after close', async () => {
  const calls = [];
  const fs = environment(async (op, args) => { calls.push([op, [...args]]); return op === 'open' ? 7 : null; });
  const file = await fs.openFileForWriting('out.pdf');
  await file.writeFile('a'); await file.writeFile('b');
  await file.close(); await file.close();
  await assert.rejects(file.writeFile('c'), /closed/);
  assert.deepEqual(calls, [['open',['out.pdf']], ['append',[7,'a']], ['append',[7,'b']], ['close',[7]]]);
});
test('host errors reject operations and unsupported no-follow never writes', async () => {
  let count = 0;
  const fs = environment(async () => { count++; throw new Error('permission denied'); });
  await assert.rejects(fs.writeFile('x', 'data'), /permission denied/);
  fs.followSymlinks = false;
  await assert.rejects(fs.writeFile('x', 'data'), /followSymlinks/);
  assert.equal(count, 1);
});
