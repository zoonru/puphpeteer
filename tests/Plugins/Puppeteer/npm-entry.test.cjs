const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const esbuild = require('esbuild');

test('committed plugin fixture matches standard esbuild output', async () => {
  const root = path.resolve(__dirname, '../../..');
  const output = path.join(root, 'tests/Fixtures/npm-entry.cjs');
  const result = await esbuild.build({
    absWorkingDir: root, entryPoints: ['tests/Fixtures/npm-entry.js'], outfile: output,
    bundle: true, platform: 'node', format: 'cjs', packages: 'external', write: false,
  });
  assert.equal(result.outputFiles[0].text, fs.readFileSync(output, 'utf8'));
});
