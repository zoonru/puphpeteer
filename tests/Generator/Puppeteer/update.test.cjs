'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {extractPublicApi} = require('../../../tools/upstream/public-api.cjs');
const {buildPhpModel} = require('../../../tools/upstream/php-model.cjs');
const {applyGeneratedFiles} = require('../../../tools/upstream/update.cjs');

test('upstream removal deletes PHP methods and classes; check leaves files intact', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'quickjs-removal-'));
    const entry = path.join(root, 'api.d.ts');
    const config = {namespace: 'Nesk\\Puphpeteer\\Puppeteer', wrapperClasses: ['Page', 'OldClass'], types: {},
        members: {'Page.removed': {}, 'OldClass.title': {}}};
    const generate = () => {
        const model = buildPhpModel({api: extractPublicApi(entry, {root}), config});
        const result = spawnSync('php', [path.resolve(__dirname, '../../../tools/php/synchronize.php')], {
            input: JSON.stringify({root, classes: model.classes}), encoding: 'utf8',
        });
        assert.equal(result.status, 0, result.stderr || result.stdout);
        return JSON.parse(result.stdout);
    };
    try {
        fs.writeFileSync(entry, 'export declare class Page { title(): string; removed(): string; readonly connected: boolean; }\nexport declare class OldClass { title(): string; }');
        let result = generate();
        applyGeneratedFiles(root, result.files, result.deletedFiles);
        const page = path.join(root, 'src/Puppeteer/Page.php');
        const oldClass = path.join(root, 'src/Puppeteer/OldClass.php');
        const runtime = path.join(root, 'src/Client.php');
        fs.writeFileSync(runtime, '<?php // handwritten runtime\n');
        assert.match(fs.readFileSync(page, 'utf8'), /function removed\(/);
        assert.ok(fs.existsSync(oldClass));
        assert.match(fs.readFileSync(page, 'utf8'), /public bool \$connected/);

        // Keep the old mappings in config: removed upstream symbols must not block cleanup.
        fs.writeFileSync(entry, 'export declare class Page { title(): string; }');
        result = generate();
        assert.deepEqual(result.deletedFiles, ['src/Puppeteer/OldClass.php']);
        const changed = applyGeneratedFiles(root, result.files, result.deletedFiles, true);
        assert.ok(changed.includes('src/Puppeteer/Page.php'));
        assert.ok(changed.includes('src/Puppeteer/OldClass.php'));
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

test('public API extraction tolerates structural terminal base types', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'quickjs-structural-base-'));
    const entry = path.join(root, 'api.d.ts');
    try {
        fs.writeFileSync(entry, 'export declare class ScreenRecording extends ReadableStream<Uint8Array> {}\n');
        const api = extractPublicApi(entry, {root});
        assert.deepEqual(api.classes.map(item => item.name), ['ScreenRecording']);
    } finally {
        fs.rmSync(root, {recursive: true, force: true});
    }
});

for (const [name, declaration, expected] of [
    ['syntax error', 'export declare class Page { broken(: string; }', /api\.d\.ts\(1,\d+\): error TS\d+/],
    ['unknown type', 'export declare class Page { value(): MissingType; }', /Cannot find name 'MissingType'/],
    ['unresolved import', 'import {Missing} from "./missing.js"; export declare class Page { value(): Missing; }', /Cannot find module/],
]) {
    test(`generation fails before writing on ${name}, including model-only mode`, () => {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'quickjs-invalid-'));
        try {
            fs.mkdirSync(path.join(root, 'upstream'));
            fs.mkdirSync(path.join(root, 'src'));
            fs.writeFileSync(path.join(root, 'upstream/config.json'), JSON.stringify({schemaVersion:1, namespace:'Nesk\\Puphpeteer\\Puppeteer', wrapperClasses:['Page']}));
            fs.writeFileSync(path.join(root, 'upstream/lock.json'), 'unchanged lock');
            fs.writeFileSync(path.join(root, 'src/Page.php'), 'unchanged wrapper');
            fs.writeFileSync(path.join(root, 'api.d.ts'), declaration);
            for (const args of [[], ['--check'], ['--model-only']]) {
                const result = fixtureGeneration(root, args);
                assert.equal(result.status, 2, result.stdout + result.stderr);
                assert.match(result.stderr, expected);
                const summary = JSON.parse(result.stdout);
                assert.equal(summary.errors, [...result.stderr.matchAll(/error TS\d+:/g)].length);
                assert.equal(summary.warnings, 0);
                assert.equal(fs.readFileSync(path.join(root, 'upstream/lock.json'), 'utf8'), 'unchanged lock');
                assert.equal(fs.readFileSync(path.join(root, 'src/Page.php'), 'utf8'), 'unchanged wrapper');
                assert.equal(fs.existsSync(path.join(root, 'upstream/coverage.json')), false);
            }
        } finally { fs.rmSync(root, {recursive:true, force:true}); }
    });
}

test('unsupported PHP mapping is reported without saving coverage or a baseline', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'quickjs-unsupported-'));
    try {
        fs.mkdirSync(path.join(root, 'upstream'));
        fs.writeFileSync(path.join(root, 'upstream/config.json'), JSON.stringify({schemaVersion:1, namespace:'Nesk\\Puphpeteer\\Puppeteer', wrapperClasses:['Page']}));
        fs.writeFileSync(path.join(root, 'api.d.ts'), 'export declare class Page { value(): symbol; }');
        const quiet = fixtureGeneration(root, []);
        assert.equal(quiet.status, 0, quiet.stderr);
        assert.deepEqual(JSON.parse(quiet.stdout).diagnostics, []);
        const result = fixtureGeneration(root, ['--verbose']);
        assert.equal(result.status, 0, result.stderr);
        const verbose = JSON.parse(result.stdout);
        const summary = JSON.parse(quiet.stdout);
        assert.ok(verbose.diagnostics.length > 0);
        assert.equal(summary.errors, 0);
        assert.equal(summary.warnings, verbose.diagnostics.length);
        assert.equal(summary.warnings, verbose.warnings);
        const progress = fixtureGeneration(root, ['--progress', '--model-only']);
        assert.equal(progress.status, 0, progress.stderr);
        const lines = progress.stdout.split('\n');
        const events = lines.filter(line => line.startsWith('@progress ')).map(line => JSON.parse(line.slice(10)));
        assert.deepEqual(events.map(event => event.step), [1,2,3,4,5,6]);
        const report = JSON.parse(lines.filter(line => !line.startsWith('@progress ')).join('\n'));
        assert.equal(report.warnings, summary.warnings);
        assert.deepEqual(report.diagnostics, []);

        assert.equal(fs.existsSync(path.join(root, 'upstream/coverage.json')), false);
    } finally { fs.rmSync(root, {recursive:true, force:true}); }
});

function fixtureGeneration(root, args) {
    // Only source fetching is replaced: exercise the actual parser, generator and CLI error handler.
    return spawnSync(process.execPath, ['-e', `
        require(${JSON.stringify(path.resolve(__dirname, '../../../tools/upstream/source-resolver.cjs'))}).resolveSources = async () => ({entry: ${JSON.stringify(path.join(root, 'api.d.ts'))}, lock: {}});
        require(${JSON.stringify(path.resolve(__dirname, '../../../tools/upstream/update.cjs'))}).runCli(${JSON.stringify(root)}, ${JSON.stringify(args)});
    `], {encoding:'utf8', timeout:60000});
}
