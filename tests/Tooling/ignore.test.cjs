'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {extractPublicApi} = require('../../tools/upstream/public-api.cjs');
const {initializeIgnore, selectApi} = require('../../tools/upstream/ignore.cjs');

const api = {
    classes: [
        {name: 'Browser', members: [{id: 'Browser.close', kind: 'method'}, {id: 'Browser.newPage', kind: 'method'}]},
        {name: 'Page', members: [{id: 'Page.evaluate', kind: 'method'}, {id: 'Page.goto', kind: 'method'}]},
    ],
    exports: ['connect'],
};

test('declaration inventory includes inheritance, aliases, static and symbol members, but not private API', () => {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'puphpeteer-ignore-'));
    try {
        const entry = path.join(directory, 'index.d.ts');
        fs.writeFileSync(path.join(directory, 'base.d.ts'), `
            export declare class Base {
                inherited(): void;
                protected hidden(): void;
                private state;
                static shared(): void;
            }
        `);
        fs.writeFileSync(entry, `
            import {Base} from './base.js';
            export declare class Page extends Base {
                #private;
                constructor();
                goto(url: string): Promise<void>;
                goto(url: URL): Promise<void>;
                $(): void;
                get closed(): boolean;
                [Symbol.dispose](): void;
                /** @internal */ internal(): void;
            }
            declare class Original { run(): void; }
            export {Original as Alias};
            /** @internal */ export declare class Secret { leak(): void; }
            export interface Options { url: string; }
            export declare const connect: () => Page;
        `);
        const result = extractPublicApi(entry);
        assert.deepEqual(result.classes.map(item => item.name), ['Alias', 'Page']);
        assert.deepEqual(result.classes[0].members, [{id: 'Alias.run', kind: 'method'}]);
        assert.deepEqual(result.classes[1].members.map(item => item.id), [
            'Page.$', 'Page.[Symbol.dispose]', 'Page.closed', 'Page.constructor',
            'Page.goto', 'Page.inherited', 'Page::shared',
        ]);
        assert.deepEqual(result.exports, ['connect']);
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
});

test('initialization leaves only the requested methods active', () => {
    const ignore = initializeIgnore(api, new Set(['Page.goto']), {version: 'fixture'});
    assert.deepEqual(ignore.classes, ['Browser']);
    assert.deepEqual(ignore.members, ['Page.evaluate']);
    assert.deepEqual(selectApi(api, ignore).activeClasses, [
        {name: 'Page', members: [{id: 'Page.goto', kind: 'method'}]},
    ]);
    assert.throws(() => initializeIgnore(api, new Set(['Page.missing']), {}), /Unknown member/);
});

test('removing a method or class exclusion enables API without any second allowlist', () => {
    const ignore = initializeIgnore(api, new Set(['Page.goto']), {});
    ignore.members = [];
    assert.deepEqual(selectApi(api, ignore).activeClasses[0].members, api.classes[1].members);
    ignore.classes = [];
    assert.deepEqual(selectApi(api, ignore).activeClasses, api.classes);
});

test('new API is active by default, while a class exclusion also covers its new members', () => {
    const ignore = initializeIgnore(api, new Set(['Page.goto']), {});
    const updated = structuredClone(api);
    updated.classes[0].members.push({id: 'Browser.newMethod', kind: 'method'});
    updated.classes[1].members.push({id: 'Page.newMethod', kind: 'method'});
    updated.classes.push({name: 'NewClass', members: [{id: 'NewClass.run', kind: 'method'}]});
    updated.exports.push('newExport');
    const before = JSON.stringify(ignore);
    const selected = selectApi(updated, ignore);
    assert.deepEqual(selected.activeClasses.map(item => item.name), ['Page', 'NewClass']);
    assert.deepEqual(selected.activeClasses[0].members.map(item => item.id), ['Page.goto', 'Page.newMethod']);
    assert.deepEqual(selected.activeExports, ['newExport']);
    assert.equal(JSON.stringify(ignore), before);
});

test('stale names and malformed configuration cannot silently hide API', () => {
    const ignore = initializeIgnore(api, new Set(['Page.goto']), {});
    ignore.members.push('Page.deleted');
    assert.deepEqual(selectApi(api, ignore).stale, [{section: 'members', id: 'Page.deleted'}]);
    ignore.members.push('Page.deleted');
    assert.throws(() => selectApi(api, ignore), /Duplicate/);
    assert.throws(() => selectApi(api, {...ignore, typo: []}), /Unknown ignore field/);
});

test('report respects the editable ignore file and never rewrites it', () => {
    const root = path.resolve(__dirname, '../..');
    const filename = path.join(root, 'upstream/ignore.json');
    const before = fs.readFileSync(filename, 'utf8');
    const result = spawnSync(process.execPath, ['tools/upstream/ignore.cjs', 'report'], {cwd: root, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    const report = JSON.parse(result.stdout);
    const entry = path.join(root, 'node_modules/puppeteer/lib/types.d.ts');
    const expected = selectApi(extractPublicApi(entry), JSON.parse(before));
    assert.deepEqual(report.activeClasses, expected.activeClasses);
    assert.deepEqual(report.activeExports, expected.activeExports);
    assert.deepEqual(report.stale, expected.stale);
    assert.equal(fs.readFileSync(filename, 'utf8'), before);
    const reinitialize = spawnSync(process.execPath, [
        'tools/upstream/ignore.cjs', 'init', '--keep=Page.goto',
    ], {cwd: root, encoding: 'utf8'});
    assert.equal(reinitialize.status, 2);
    assert.match(reinitialize.stderr, /EEXIST/);
    assert.equal(fs.readFileSync(filename, 'utf8'), before);
});
