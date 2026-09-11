'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const {generateAliases} = require('./aliases.cjs');

test('aliases follow current generated classes, including removal', () => {
    const classes = [{name:'Page',fqcn:'Nesk\\Puphpeteer\\Page'}, {name:'Browser',fqcn:'Nesk\\Puphpeteer\\Browser'}];
    const first = generateAliases(classes).content;
    const second = generateAliases(classes.slice(0, 1)).content;
    assert.ok(first.includes('Resources\\Browser'));
    assert.ok(!second.includes('Resources\\Browser'));
    assert.ok(second.includes('Resources\\Page'));
    assert.ok(second.includes('Nesk\\Rialto\\Data\\JsFunction'));
});

test('best effort aliases do not replace a class already provided by the application', () => {
    const autoload = JSON.stringify(path.resolve(__dirname, '../../vendor/autoload.php'));
    const result = spawnSync('php', ['-r', `namespace Nesk\\Rialto\\Data { class JsFunction { const ORIGINAL = true; } } namespace { require ${autoload}; if (!\\Nesk\\Rialto\\Data\\JsFunction::ORIGINAL) { exit(1); } }`], {encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stderr, '');
});
