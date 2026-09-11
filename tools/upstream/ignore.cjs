'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {extractPublicApi} = require('./public-api.cjs');

function validate(ignore) {
    if (!ignore || ignore.schemaVersion !== 1) throw new Error('Unsupported ignore schema');
    for (const key of Object.keys(ignore)) {
        if (!['schemaVersion', 'initializedFrom', 'classes', 'members', 'exports'].includes(key)) {
            throw new Error(`Unknown ignore field: ${key}`);
        }
    }
    for (const key of ['classes', 'members', 'exports']) {
        const values = ignore[key];
        if (!Array.isArray(values) || values.some(value => typeof value !== 'string' || !value)) {
            throw new Error(`ignore.${key} must be an array of nonempty IDs`);
        }
        if (new Set(values).size !== values.length) throw new Error(`Duplicate ignore.${key} entries`);
    }
}

function initializeIgnore(api, keep, initializedFrom) {
    const allMembers = new Set(api.classes.flatMap(item => item.members.map(member => member.id)));
    for (const id of keep) {
        if (!allMembers.has(id)) throw new Error(`Unknown member to keep: ${id}`);
    }
    return {
        schemaVersion: 1,
        initializedFrom,
        classes: api.classes.filter(item => !item.members.some(member => keep.has(member.id)))
            .map(item => item.name),
        members: api.classes.filter(item => item.members.some(member => keep.has(member.id)))
            .flatMap(item => item.members.filter(member => !keep.has(member.id)).map(member => member.id)),
        exports: api.exports,
    };
}

/** Shared selection rule for future scaffold, test discovery and verification commands. */
function selectApi(api, ignore) {
    validate(ignore);
    const classNames = new Set(api.classes.map(item => item.name));
    const memberIds = new Set(api.classes.flatMap(item => item.members.map(member => member.id)));
    const exportNames = new Set(api.exports);
    const stale = [];
    for (const [key, known] of [['classes', classNames], ['members', memberIds], ['exports', exportNames]]) {
        for (const id of ignore[key]) if (!known.has(id)) stale.push({section: key, id});
    }
    const excludedClasses = new Set(ignore.classes);
    const excludedMembers = new Set(ignore.members);
    const excludedExports = new Set(ignore.exports);
    return {
        activeClasses: api.classes.filter(item => !excludedClasses.has(item.name)).map(item => ({
            name: item.name,
            members: item.members.filter(member => !excludedMembers.has(member.id)),
        })),
        activeExports: api.exports.filter(name => !excludedExports.has(name)),
        stale,
    };
}

function main() {
    const [command, ...args] = process.argv.slice(2);
    if (!['init', 'report'].includes(command)
        || args.some(arg => !arg.startsWith('--keep='))
        || args.length > 1 || (command === 'report' && args.length)) {
        throw new Error('Usage: node tools/upstream/ignore.cjs init --keep=Class.method,... | report');
    }
    const root = path.resolve(__dirname, '../..');
    const packageJson = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
    const version = packageJson.dependencies.puppeteer;
    for (const name of ['puppeteer', 'puppeteer-core']) {
        const installed = JSON.parse(fs.readFileSync(path.join(root, 'node_modules', name, 'package.json'), 'utf8'));
        if (installed.version !== version) throw new Error(`${name} must match package.json (${version})`);
    }
    const packageRoot = path.join(root, 'node_modules/puppeteer');
    const installed = JSON.parse(fs.readFileSync(path.join(packageRoot, 'package.json'), 'utf8'));
    const api = extractPublicApi(path.resolve(packageRoot, installed.types));
    const destination = path.join(root, 'upstream/ignore.json');
    if (command === 'init') {
        const keep = new Set((args[0]?.slice('--keep='.length) || '').split(',').filter(Boolean));
        if (!keep.size) throw new Error('init requires an explicit nonempty --keep list');
        const ignore = initializeIgnore(api, keep, {package: 'puppeteer', version});
        fs.mkdirSync(path.dirname(destination), {recursive: true});
        // Initialization is deliberately one-shot: never restore exclusions removed by a developer.
        fs.writeFileSync(destination, JSON.stringify(ignore, null, 2) + '\n', {flag: 'wx'});
    }
    const ignore = JSON.parse(fs.readFileSync(destination, 'utf8'));
    const selection = selectApi(api, ignore);
    process.stdout.write(JSON.stringify({
        package: 'puppeteer', version,
        totalClasses: api.classes.length,
        totalMembers: api.classes.reduce((count, item) => count + item.members.length, 0),
        ...selection,
    }, null, 2) + '\n');
    if (selection.stale.length) process.exitCode = 1;
}

module.exports = {initializeIgnore, selectApi};
if (require.main === module) {
    try { main(); } catch (error) { console.error(error.message); process.exitCode = 2; }
}
