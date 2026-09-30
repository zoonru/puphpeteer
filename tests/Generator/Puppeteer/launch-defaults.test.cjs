'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const puppeteer = require('puppeteer-core');

test('PHP launch arguments match installed Puppeteer across repeated launch modes', async () => {
 const root = path.resolve(__dirname, '../../..');
 const options = [];
 for (const headless of [true, false, 'shell']) for (const devtools of [false, true]) {
  options.push({headless, devtools});
  options.push({headless, devtools, args: ['--custom', 'https://example.com']});
  options.push({headless, devtools});
 }
 const result = spawnSync('php', ['-r', 'require $argv[1]; $puppeteer = new Nesk\\Puphpeteer\\Puppeteer\\Puppeteer(); echo json_encode(array_map($puppeteer->defaultArgs(...), json_decode($argv[2], true)));', path.join(root, 'vendor/autoload.php'), JSON.stringify(options)], {encoding:'utf8'});
 assert.equal(result.status, 0, result.stderr);
 const actual = JSON.parse(result.stdout);
 for (let index = 0; index < options.length; index++) {
  assert.deepEqual(actual[index], await puppeteer.defaultArgs(options[index]), JSON.stringify(options[index]));
 }
});
