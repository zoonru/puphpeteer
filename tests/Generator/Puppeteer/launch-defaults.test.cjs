'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const puppeteer = require('puppeteer-core');

test('PHP launch arguments match installed Puppeteer across launch modes', async () => {
 const root = path.resolve(__dirname, '../../..');
 for (const headless of [true, false, 'shell']) for (const devtools of [false, true]) {
  const options = {headless, devtools};
  const result = spawnSync('php', ['-r', 'require $argv[1]; echo json_encode((new Nesk\\Puphpeteer\\Puppeteer\\Puppeteer())->defaultArgs(json_decode($argv[2], true)));', path.join(root, 'vendor/autoload.php'), JSON.stringify(options)], {encoding:'utf8'});
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), await puppeteer.defaultArgs(options), JSON.stringify(options));
 }
});
