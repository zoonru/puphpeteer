'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {extractPublicApi} = require('./public-api.cjs');
const {buildPhpModel} = require('./php-model.cjs');
const {applyGeneratedFiles} = require('./update.cjs');

test('upstream removal deletes PHP methods and classes; check leaves files intact', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'quickjs-removal-'));
    const entry = path.join(root, 'api.d.ts');
    const config = {namespace: 'Nesk\\Puphpeteer', wrapperClasses: ['Page', 'OldClass'], types: {},
        members: {'Page.removed': {}, 'OldClass.title': {}}};
    const generate = () => {
        const model = buildPhpModel({api: extractPublicApi(entry, {root}), config});
        const result = spawnSync('php', [path.resolve(__dirname, '../php/synchronize.php')], {
            input: JSON.stringify({root, classes: model.classes}), encoding: 'utf8',
        });
        assert.equal(result.status, 0, result.stderr || result.stdout);
        return JSON.parse(result.stdout);
    };
    try {
        fs.writeFileSync(entry, 'export declare class Page { title(): string; removed(): string; readonly connected: boolean; }\nexport declare class OldClass { title(): string; }');
        let result = generate();
        applyGeneratedFiles(root, result.files, result.deletedFiles);
        const page = path.join(root, 'src/Page.php');
        const oldClass = path.join(root, 'src/OldClass.php');
        const runtime = path.join(root, 'src/Client.php');
        fs.writeFileSync(runtime, '<?php // handwritten runtime\n');
        assert.match(fs.readFileSync(page, 'utf8'), /function removed\(/);
        assert.ok(fs.existsSync(oldClass));
        assert.match(fs.readFileSync(page, 'utf8'), /public bool \$connected/);

        // Keep the old mappings in config: removed upstream symbols must not block cleanup.
        fs.writeFileSync(entry, 'export declare class Page { title(): string; }');
        result = generate();
        assert.deepEqual(result.deletedFiles, ['src/OldClass.php']);
        const changed = applyGeneratedFiles(root, result.files, result.deletedFiles, true);
        assert.ok(changed.includes('src/Page.php'));
        assert.ok(changed.includes('src/OldClass.php'));
        assert.match(fs.readFileSync(page, 'utf8'), /function removed\(/);
        assert.ok(fs.existsSync(oldClass));

        applyGeneratedFiles(root, result.files, result.deletedFiles);
        assert.doesNotMatch(fs.readFileSync(page, 'utf8'), /function removed\(/);
        assert.doesNotMatch(fs.readFileSync(page, 'utf8'), /\$connected/);
        assert.match(fs.readFileSync(page, 'utf8'), /function title\(/);
        assert.equal(fs.existsSync(oldClass), false);
        assert.equal(fs.readFileSync(runtime, 'utf8'), '<?php // handwritten runtime\n');
        result = generate();
        assert.deepEqual(applyGeneratedFiles(root, result.files, result.deletedFiles, true), []);

        fs.writeFileSync(entry, 'export {};');
        result = generate();
        applyGeneratedFiles(root, result.files, result.deletedFiles);
        assert.equal(fs.existsSync(page), false);
        assert.ok(fs.existsSync(runtime));
    } finally {
        fs.rmSync(root, {recursive: true, force: true});
    }
});
