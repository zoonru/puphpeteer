#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const cliProgress = require('cli-progress');
const {extractPublicApi} = require('./public-api.cjs');
const {selectApi} = require('./ignore.cjs');
const {resolveSources} = require('./source-resolver.cjs');
const {apiSourceLinks} = require('./source-links.cjs');
const {buildPhpModel} = require('./php-model.cjs');
const {trackTests} = require('./test-tracker.cjs');
const {readJson, json, inside, validateConfig, diagnostic, sortDiagnostics} = require('./model.cjs');
const toolRoot = path.resolve(__dirname, '../..');

function stableCatalog(catalog) {
    return {...catalog, tests: catalog.tests.map(({change, ...test}) => test)};
}
function semantics(value) {
    if (Array.isArray(value)) return value.map(semantics);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value)
        .filter(([key]) => !['source', 'origin', 'line', 'declaration'].includes(key)).map(([key, item]) => [key, semantics(item)]));
    return value;
}
function diffApi(previous, current) {
    const flatten = api => new Map((api?.classes || []).flatMap(({members, ...item}) => [[`class:${item.name}`, item], ...members.map(member => [member.id, member])])
        .concat((api?.declarations || []).map(item => [item.name, item]))
        .concat((api?.types || []).map(item => [`type:${item.qualifiedName || item.name}`, item])));
    const before = flatten(previous), after = flatten(current);
    return {added: [...after.keys()].filter(id => !before.has(id)).sort(),
        removed: [...before.keys()].filter(id => !after.has(id)).sort(),
        changed: [...after.keys()].filter(id => before.has(id) && json(semantics(before.get(id))) !== json(semantics(after.get(id)))).sort()};
}
function phpSync(root, model) {
    const result = spawnSync('php', [path.join(toolRoot, 'tools/php/synchronize.php')], {
        cwd: root, input: json({root, classes: model.classes, knownMemberIds: model.knownMemberIds}),
        encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, timeout: 60000,
    });
    if (result.error || ![0, 1].includes(result.status)) throw new Error(`PHP synchronizer failed: ${result.stderr || result.error?.message || result.stdout}`);
    try { return {...JSON.parse(result.stdout), exitCode: result.status}; }
    catch { throw new Error(`PHP synchronizer returned invalid JSON: ${result.stdout}\n${result.stderr}`); }
}
async function updatePhp(root, {offline = false, progress = () => {}} = {}) {
    progress(0, 'Resolve sources');
    const config = validateConfig(readJson(path.join(root, 'upstream/config.json')));
    const ignore = readJson(path.join(root, 'upstream/ignore.json'));
    const testIgnore = readJson(path.join(root, 'upstream/test-ignore.json'));
    const source = await resolveSources({root, offline});
    progress(1, 'Extract public API');
    const api = extractPublicApi(source.entry, {root});
    const selection = selectApi(api, ignore);
    progress(2, 'Map PHP declarations and types');
    const model = buildPhpModel({root, api, selection, config, links: apiSourceLinks(source, {activeClasses: [...api.classes, ...api.types.filter(type => type.kind === 'interface')]})});
    const previousApi = fs.existsSync(path.join(root, 'upstream/api.json')) ? readJson(path.join(root, 'upstream/api.json')) : null;
    const previousTests = fs.existsSync(path.join(root, 'upstream/tests.json')) ? readJson(path.join(root, 'upstream/tests.json')) : null;
    if (previousApi && (previousApi.schemaVersion !== 1 || !Array.isArray(previousApi.classes) || !Array.isArray(previousApi.types) || !Array.isArray(previousApi.exports))) throw new Error('Unsupported API catalog schema');
    if (previousTests && (previousTests.schemaVersion !== 1 || !Array.isArray(previousTests.tests))) throw new Error('Unsupported test catalog schema');
    progress(3, 'Synchronize test catalog');
    const tracker = trackTests({root, upstreamLock: source.lock, sourceRoot: source.repositoryRoot, api, selection, config: {...config,
        testRoots: [path.relative(source.repositoryRoot, source.testsRoot)]}, previous: previousTests, testIgnore});
    progress(4, 'Synchronize PHP declarations');
    const sync = phpSync(root, model);
    const diagnostics = [...(api.diagnostics || []), ...model.diagnostics, ...tracker.diagnostics, ...(sync.diagnostics || []),
        ...selection.stale.map(item => diagnostic('ignore.stale', 'ApiSelector', item.id, 'Existing API ID', item.section))];
    const generated = [{path: 'upstream/api.json', content: json(api)}, {path: 'upstream/lock.json', content: json(source.lock)},
        {path: 'upstream/tests.json', content: json(stableCatalog(tracker.catalog))}];
    progress(5, 'Write generated files');
    const changedFiles = [];
    for (const file of [...generated, ...(sync.files || []), ...tracker.stubs]) {
        const destination = inside(root, file.path);
        if (fs.existsSync(destination) && fs.readFileSync(destination, 'utf8') === file.content) continue;
        fs.mkdirSync(path.dirname(destination), {recursive: true});
        fs.writeFileSync(destination, file.content);
        changedFiles.push(file.path);
    }
    progress(6, 'Complete');
    return {source: source.lock, changedFiles, statistics: statistics(api, selection, model, tracker, sync), apiDiff: diffApi(previousApi, api),
        diagnostics: sortDiagnostics(diagnostics), exitCode: sync.exitCode};
}


/** Counts declarations and mappings only, never implementation or assertion coverage. */
function statistics(api, selection, model, tracker, sync) {
    const activeClasses = new Set(selection.activeClasses.map(item => item.name));
    const activeMembers = selection.activeClasses.flatMap(item => item.members.map(member => member.id));
    const emittedFiles = new Set((sync.files || []).map(file => file.path));
    const blockers = [...model.diagnostics, ...(sync.diagnostics || [])];
    const synchronized = new Set();
    for (const item of model.classes.filter(item => activeClasses.has(item.name))) {
        if (!emittedFiles.has(item.file)) continue;
        if (blockers.some(issue => issue.symbolId === item.name || issue.symbolId === `class:${item.name}`)) continue;
        for (const member of item.members) {
            if (!blockers.some(issue => issue.symbolId === member.id)) synchronized.add(member.id);
        }
    }
    const tests = tracker.catalog.tests;
    const mapped = new Set(tests.filter(test => test.status === 'required' && test.origin).flatMap(test => test.api));
    const statuses = {};
    for (const test of tests) statuses[test.status] = (statuses[test.status] || 0) + 1;
    return {
        classes: {active: activeClasses.size, upstream: api.classes.length,
            supporting: model.classes.filter(item => !activeClasses.has(item.name)).length},
        members: {active: activeMembers.length, upstream: api.classes.reduce((count, item) => count + item.members.length, 0),
            synchronized: activeMembers.filter(id => synchronized.has(id)).length,
            mappedToUpstreamTests: activeMembers.filter(id => mapped.has(id)).length},
        tests: {total: tests.length, statuses},
        conflicts: (sync.diagnostics || []).filter(issue => issue.code === 'contract.conflict').length,
    };
}
function ratio(count, total) {
    return `${count}/${total} (${total ? (count * 100 / total).toFixed(1) + '%' : 'n/a'})`;
}
function formatResult(result) {
    const {classes, members, tests, conflicts} = result.statistics;
    const lines = [
        `Updated ${result.changedFiles.length} files (exit ${result.exitCode})`,
        `Classes: ${classes.active}/${classes.upstream} upstream classes active; ${classes.supporting} supporting declarations`,
        `Members: ${members.active}/${members.upstream} upstream members active`,
        `Active member declarations synchronized: ${ratio(members.synchronized, members.active)}`,
        `Active API mapped to upstream tests: ${ratio(members.mappedToUpstreamTests, members.active)}`,
        'These percentages describe declarations and test mappings, not implementation or assertion coverage.',
        `Tests: ${tests.total} discovered; ${Object.entries(tests.statuses).sort().map(([status, count]) => `${count} ${status}`).join('; ')}`,
        `Conflicts: ${conflicts}`,
        `API changes: ${result.apiDiff.added.length} added, ${result.apiDiff.changed.length} changed, ${result.apiDiff.removed.length} removed`,
    ];
    if (result.source) {
        const source = result.source;
        lines.splice(1, 0, `Source: ${source.package.name} ${source.package.version}; ${source.tests?.revision ? 'commit ' + source.tests.revision : 'revision unavailable'}`);
    }
    if (result.changedFiles.length) lines.push('', 'Changed files:', ...result.changedFiles.map(file => `  ${file}`));
    if (result.diagnostics.length) {
        const groups = new Map();
        for (const item of result.diagnostics) {
            const key = item.code.startsWith('type.') && item.actual !== undefined
                ? `${item.code}: ${JSON.stringify(item.actual)}` : item.code;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(item);
        }
        // Keep terminal output readable; JSON retains every diagnostic and its full detail.
        const compact = value => value.replace(/\s+/g, ' ').slice(0, 240);
        lines.push('', `Diagnostics (${result.diagnostics.length}; use --json for the full list):`);
        for (const [key, items] of [...groups].sort(([a], [b]) => a.localeCompare(b))) {
            lines.push(`  ${compact(key)}: ${items.length}`);
            for (const item of items.slice(0, 3)) {
                lines.push(`    ${compact(`${item.symbolId || item.testId || ''}: ${item.message || JSON.stringify(item.actual ?? null)}`)}`);
            }
            if (items.length > 3) lines.push(`    ... ${items.length - 3} more`);
        }
    }
    return lines.join('\n') + '\n';
}

async function main(args = process.argv.slice(2)) {
    let root = toolRoot, offline = false, text = true;
    while (args.length) {
        const arg = args.shift();
        if (arg === '--root' && args.length) root = path.resolve(args.shift());
        else if (arg === '--offline') offline = true;
        else if (arg === '--json') text = false;
        else throw new Error('Usage: update.cjs [--root PATH] [--offline] [--json]');
    }
    const bar = text && process.stderr.isTTY ? new cliProgress.SingleBar({
        stream: process.stderr, format: 'update-php [{bar}] {value}/{total} {stage}',
        hideCursor: true, synchronousUpdate: true,
    }, cliProgress.Presets.shades_classic) : null;
    bar?.start(6, 0, {stage: 'Starting'});
    let result;
    try {
        result = await updatePhp(root, {offline, progress: (value, stage) => bar?.update(value, {stage})});
    } finally {
        bar?.stop();
    }
    if (text) process.stdout.write(formatResult(result));
    else process.stdout.write(json(result));
    return result.exitCode;
}
if (require.main === module) main().then(code => { process.exitCode = code; }).catch(error => {
    process.stderr.write(`${error.message}\n`); process.exitCode = 2;
});
