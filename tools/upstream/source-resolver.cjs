'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFile} = require('node:child_process');
const {promisify} = require('node:util');
const execute = promisify(execFile);
const readJson = filename => JSON.parse(fs.readFileSync(filename, 'utf8'));
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');

async function command(file, args, cwd) {
    try {
        return (await execute(file, args, {cwd, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024, timeout: 180000})).stdout.trim();
    } catch (error) {
        throw new Error(`${file} ${args.join(' ')} failed: ${error.stderr || error.message}`, {cause: error});
    }
}

function inside(root, relative) {
    const filename = path.resolve(root, relative);
    if (filename !== root && !filename.startsWith(`${root}${path.sep}`)) throw new Error(`Path escapes source root: ${relative}`);
    return filename;
}

/** Resolve exact npm declarations and the matching immutable repository revision. */
async function resolveSources(options = {}) {
    const root = path.resolve(options.root || process.cwd());
    const name = 'puppeteer-core';
    const repository = 'https://github.com/puppeteer/puppeteer.git';
    const packageSubdirectory = 'packages/puppeteer-core';
    const testsPath = 'test/src';
    const manifest = readJson(path.join(root, 'package.json'));
    const npmLockBytes = fs.readFileSync(path.join(root, 'package-lock.json'));
    const npmLock = JSON.parse(npmLockBytes);
    const version = manifest.dependencies?.[name] || manifest.devDependencies?.[name];
    if (!/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(version || '')) throw new Error(`${name} must have an exact version in package.json`);
    if (![2, 3].includes(npmLock.lockfileVersion) || !npmLock.packages) throw new Error('package-lock.json must use supported npm lockfile version 2 or 3');
    const locked = npmLock.packages[`node_modules/${name}`];
    const rootVersion = npmLock.packages['']?.dependencies?.[name] || npmLock.packages['']?.devDependencies?.[name];
    if (rootVersion !== version || locked?.version !== version || !locked.integrity || !locked.resolved) throw new Error(`package-lock.json does not pin ${name}@${version}; update it explicitly before resolving sources`);
    const packageRoot = inside(root, `node_modules/${name}`);
    const installedMatches = () => {
        try {
            if (readJson(path.join(packageRoot, 'package.json')).version !== version) return false;
            for (const dependency of Object.keys({...manifest.dependencies, ...manifest.devDependencies})) {
                if (readJson(path.join(root, 'node_modules', dependency, 'package.json')).version !== npmLock.packages[`node_modules/${dependency}`]?.version) return false;
            }
            return true;
        } catch { return false; }
    };
    if (!installedMatches()) {
        if (options.offline) throw new Error('Installed npm dependencies do not match package-lock.json; run npm ci --ignore-scripts');
        await command('npm', ['ci', '--ignore-scripts', '--no-audit', '--no-fund'], root);
        if (!installedMatches()) throw new Error('npm ci did not install the locked dependencies');
        if (!fs.readFileSync(path.join(root, 'package-lock.json')).equals(npmLockBytes)) throw new Error('npm ci unexpectedly changed package-lock.json');
    }
    const installed = readJson(path.join(packageRoot, 'package.json'));
    const entry = inside(packageRoot, installed.types || installed.typings || 'index.d.ts');
    if (!fs.existsSync(entry)) throw new Error(`Missing public declaration entry: ${entry}`);
    const lockFile = path.join(root, 'upstream/lock.json');
    const previous = fs.existsSync(lockFile) ? readJson(lockFile) : null;
    if (previous && previous.schemaVersion !== 1) throw new Error(`Unsupported upstream lock schema: ${previous.schemaVersion}`);
    const tag = `puppeteer-v${version}`;
    let revision;
    if (previous?.package?.name === name && previous.package.version === version && previous.tests?.repository === repository && previous.tests?.tag === tag) {
        revision = previous.tests.revision;
        if (!/^[a-f0-9]{40}$/.test(revision || '')) throw new Error('Invalid revision in upstream lock');
    } else {
        if (options.offline) throw new Error('No matching pinned test revision is available in upstream/lock.json');
        const references = await command('git', ['ls-remote', '--exit-code', repository, `refs/tags/${tag}`, `refs/tags/${tag}^{}`], root);
        const rows = references.split('\n').map(line => line.split(/\s+/));
        revision = rows.find(row => row[1] === `refs/tags/${tag}^{}`)?.[0] || rows.find(row => row[1] === `refs/tags/${tag}`)?.[0];
        if (!/^[a-f0-9]{40}$/.test(revision || '')) throw new Error(`Cannot resolve immutable test revision for ${tag}`);
    }
    const cache = path.resolve(path.join(root, '.build/upstream'));
    const repositoryRoot = path.join(cache, sha256(repository).slice(0, 12), revision);
    if (!fs.existsSync(path.join(repositoryRoot, '.git'))) {
        if (options.offline) throw new Error(`Pinned test repository is not cached: ${revision}`);
        fs.mkdirSync(path.dirname(repositoryRoot), {recursive: true});
        const staging = fs.mkdtempSync(path.join(path.dirname(repositoryRoot), '.fetch-'));
        try {
            await command('git', ['init', '--quiet'], staging);
            await command('git', ['remote', 'add', 'origin', repository], staging);
            await command('git', ['fetch', '--depth=1', 'origin', revision], staging);
            await command('git', ['checkout', '--quiet', '--detach', 'FETCH_HEAD'], staging);
            fs.renameSync(staging, repositoryRoot);
        } finally { fs.rmSync(staging, {recursive: true, force: true}); }
    }
    const actualRevision = await command('git', ['rev-parse', 'HEAD'], repositoryRoot);
    if (actualRevision !== revision) throw new Error(`Cached test checkout has incorrect revision: ${actualRevision}`);
    if (await command('git', ['--no-optional-locks', 'status', '--porcelain', '--untracked-files=all'], repositoryRoot)) throw new Error('Cached test repository has local changes; remove the cache or restore it before updating');
    const upstreamManifest = readJson(inside(repositoryRoot, `${packageSubdirectory}/package.json`));
    if (upstreamManifest.name !== name || upstreamManifest.version !== version) throw new Error(`Test revision ${revision} does not match ${name}@${version}`);
    const testsRoot = inside(repositoryRoot, testsPath);
    if (!fs.statSync(testsRoot).isDirectory()) throw new Error(`Upstream test directory is missing: ${testsPath}`);
    const tools = {};
    for (const dependency of Object.keys({...manifest.dependencies, ...manifest.devDependencies}).sort()) {
        const item = npmLock.packages[`node_modules/${dependency}`];
        tools[dependency] = {version: item.version, integrity: item.integrity};
    }
    const lock = {schemaVersion: 1,
        package: {name, version, resolved: locked.resolved, integrity: locked.integrity, entry: path.relative(packageRoot, entry).split(path.sep).join('/'), declarationSha256: sha256(fs.readFileSync(entry))},
        npmLockSha256: sha256(npmLockBytes), tools,
        tests: {repository, tag, revision, packageManifest: `${packageSubdirectory}/package.json`, verifiedPackageVersion: upstreamManifest.version, testsPath,
            evidence: 'Version-specific repository tag resolved to a commit; package manifest at that commit matches the npm package name and version.'},
    };
    return {entry, packageRoot, testsRoot, repositoryRoot, lock};
}

module.exports = {resolveSources};
