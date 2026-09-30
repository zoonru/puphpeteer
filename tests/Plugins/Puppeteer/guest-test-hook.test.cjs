const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');

test('registry hook is restricted to the dedicated test bundle', () => {
  const root = path.resolve(__dirname, '../../..');
  const productionPath = path.join(root, 'resources/puppeteer.js');
  const defaultsPath = path.join(root, 'resources/launch-defaults.json');
  const production = fs.readFileSync(productionPath);
  const defaults = fs.readFileSync(defaultsPath);
  execFileSync(process.execPath, ['tools/build.cjs', '--test'], {cwd: root});
  const testBundle = fs.readFileSync(path.join(root, '.build/puppeteer.test.js'), 'utf8');
  assert.ok(testBundle.includes('__quickjsTest'));
  assert.ok(testBundle.includes('functionCacheSize'));
  assert.ok(!production.includes('__quickjsTest'));
  assert.ok(!production.includes('functionCacheSize'));
  assert.deepEqual(fs.readFileSync(productionPath), production);
  assert.deepEqual(fs.readFileSync(defaultsPath), defaults);
});
