'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const ts = require('typescript');
const {spawnSync} = require('node:child_process');
const {githubLink} = require('./source-links.cjs');

const slash = value => value.split(path.sep).join('/');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');

function filesBelow(directory) {
    if (!fs.existsSync(directory)) return [];
    return fs.readdirSync(directory, {withFileTypes: true}).sort((a, b) => a.name.localeCompare(b.name))
        .flatMap(entry => entry.isDirectory() ? filesBelow(path.join(directory, entry.name))
            : entry.isFile() ? [path.join(directory, entry.name)] : []);
}

function localPath(root, relative) {
    if (typeof relative !== 'string' || !relative || path.isAbsolute(relative)) {
        throw new Error('Expected a nonempty relative project path');
    }
    const resolved = path.resolve(root, relative);
    if (!resolved.startsWith(path.resolve(root) + path.sep)) throw new Error(`Path escapes project: ${relative}`);
    return resolved;
}

function validateTestIgnore(value) {
    if (!value || value.schemaVersion !== 1 || !Array.isArray(value.tests)
        || Object.keys(value).some(key => !['schemaVersion', 'tests'].includes(key))) {
        throw new Error('Invalid test-ignore schema');
    }
    const result = new Map();
    for (const item of value.tests) {
        if (!item || typeof item.id !== 'string' || !item.id.trim()
            || typeof item.reason !== 'string' || !item.reason.trim()
            || Object.keys(item).some(key => !['id', 'reason'].includes(key))) {
            throw new Error('Test exclusions require an exact id and nonempty reason');
        }
        if (result.has(item.id)) throw new Error(`Duplicate test exclusion: ${item.id}`);
        result.set(item.id, item.reason);
    }
    return result;
}

function callKind(expression) {
    const pieces = expression.getText().split('.');
    return ['describe', 'it', 'test'].includes(pieces[0]) ? pieces[0] : null;
}

/** Only test dependencies are followed; client implementation imports are not tracked. */
function dependencies(file, sourceRoot, cache, seen = new Set()) {
    if (seen.has(file)) return [];
    seen.add(file);
    const relative = slash(path.relative(sourceRoot, file));
    if (!cache.has(file)) cache.set(file, hash(fs.readFileSync(file)));
    const result = [{path: relative, hash: cache.get(file)}];
    if (!/\.[cm]?[jt]sx?$/.test(file)) return result;
    const source = ts.createSourceFile(file, fs.readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true);
    const roots = ['test', 'tests'].map(name => path.resolve(sourceRoot, name) + path.sep);
    for (const statement of source.statements) {
        if (!ts.isImportDeclaration(statement) && !ts.isExportDeclaration(statement)) continue;
        const specifier = statement.moduleSpecifier;
        if (!specifier || !ts.isStringLiteral(specifier) || !specifier.text.startsWith('.')) continue;
        const base = path.resolve(path.dirname(file), specifier.text);
        const target = [base, base.replace(/\.js$/, '.ts'), `${base}.ts`, `${base}.js`, path.join(base, 'index.ts')]
            .find(candidate => fs.existsSync(candidate) && fs.statSync(candidate).isFile());
        if (target && roots.some(root => target.startsWith(root))) {
            result.push(...dependencies(target, sourceRoot, cache, seen));
        }
    }
    return result;
}

function stubFor(id, phpTest, upstreamUrl = null) {
    const className = path.basename(phpTest, '.php');
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(className)) throw new Error(`Invalid test class path: ${phpTest}`);
    const description = id.replace(/[\r\n]/g, ' ').replace(/\*\//g, '* /');
    return `<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\n/** Upstream scenario: ${description} */\nfinal class ${className} extends TestCase\n{\n    ${upstreamUrl ? `/** @see ${upstreamUrl} Upstream test */\n    ` : ''}public function testUpstreamBehavior(): void\n    {\n        self::markTestIncomplete('Implement the upstream assertions.');\n    }\n}\n`;
}

function identifier(value, fallback) {
    const name = value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').split(/[^A-Za-z0-9]+/)
        .filter(Boolean).map(word => word[0].toUpperCase() + word.slice(1)).join('');
    return name ? (/^[0-9]/.test(name) ? `Case${name}` : name) : fallback;
}

function readableNames(entries, previousTests, mappings) {
    const groups = new Map();
    for (const entry of entries) {
        const filename = identifier(path.basename(entry.origin?.path || 'Own').replace(/\.(spec|test)\.[^.]+$/, ''), 'Upstream');
        const suites = entry.suites || [];
        const groupId = `${entry.origin?.path || 'own'}::${suites[0] || ''}`;
        if (!groups.has(groupId)) groups.set(groupId, {id: groupId, filename, name: identifier(suites[0] || filename, filename), entries: []});
        groups.get(groupId).entries.push(entry);
    }
    const counts = new Map();
    for (const group of groups.values()) counts.set(group.name.toLowerCase(), (counts.get(group.name.toLowerCase()) || 0) + 1);
    for (const group of groups.values()) if (counts.get(group.name.toLowerCase()) > 1) group.name = group.filename + group.name;
    const expanded = new Map();
    for (const group of groups.values()) expanded.set(group.name.toLowerCase(), (expanded.get(group.name.toLowerCase()) || 0) + 1);
    const names = new Map();
    for (const group of groups.values()) {
        if (expanded.get(group.name.toLowerCase()) > 1) group.name += hash(group.id).slice(0, 8);
        const methodNames = group.entries.map(entry => 'test' + identifier([...(entry.suites || []).slice(1), entry.title || entry.id.replace(/^own:/, '')].join(' '), 'UpstreamBehavior'));
        for (const [index, entry] of group.entries.entries()) {
            let method = methodNames[index];
            if (methodNames.filter(name => name.toLowerCase() === method.toLowerCase()).length > 1) method += hash(entry.id).slice(0, 8);
            names.set(entry.id, {phpTest: `tests/Parity/${group.name}Test.php`, phpMethod: method});
        }
    }
    // Existing catalog names own their identifiers. New cases cannot displace them.
    const reserved = new Map();
    const owners = new Map();
    const prior = new Map();
    for (const group of groups.values()) for (const entry of group.entries) {
        const explicit = entry.own ? entry : mappings[entry.id];
        const old = explicit?.phpTest ? explicit : previousTests.get(entry.id);
        if (!old?.phpTest) continue;
        if (!old.phpMethod && !explicit?.phpTest) continue;
        prior.set(entry.id, {phpTest: old.phpTest, phpMethod: old.phpMethod || null});
        owners.set(old.phpTest.toLowerCase(), group.id);
        if (old.phpMethod) reserved.set(`${old.phpTest}::${old.phpMethod}`.toLowerCase(), entry.id);
    }
    for (const group of groups.values()) {
        const established = group.entries.map(entry => prior.get(entry.id)).find(name => name?.phpMethod);
        for (const entry of group.entries) {
            if (prior.has(entry.id)) { names.set(entry.id, prior.get(entry.id)); continue; }
            let {phpTest, phpMethod} = names.get(entry.id);
            if (established) phpTest = established.phpTest;
            else if (owners.has(phpTest.toLowerCase()) && owners.get(phpTest.toLowerCase()) !== group.id) {
                phpTest = phpTest.replace(/Test\.php$/, `${hash(group.id).slice(0, 8)}Test.php`);
            }
            owners.set(phpTest.toLowerCase(), group.id);
            if (reserved.has(`${phpTest}::${phpMethod}`.toLowerCase())) phpMethod += hash(entry.id).slice(0, 8);
            const key = `${phpTest}::${phpMethod}`.toLowerCase();
            if (reserved.has(key)) throw new Error(`PHP test identifier collision: ${key}`);
            reserved.set(key, entry.id);
            names.set(entry.id, {phpTest, phpMethod});
        }
    }
    return names;
}

function groupedStub(phpTest, tests) {
    const name = path.basename(phpTest, '.php');
    if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) throw new Error(`Invalid test class path: ${phpTest}`);
    const methods = tests.map(test => {
        if (!/^test[A-Za-z0-9_]+$/.test(test.phpMethod)) throw new Error(`Invalid PHP test method: ${test.phpMethod}`);
        const description = test.id.replace(/[\r\n]/g, ' ').replace(/\*\//g, '* /');
        return `    /**\n     * Upstream scenario: ${description}\n${test.upstreamUrl ? `     * @see ${test.upstreamUrl} Upstream test\n` : ''}     */\n    public function ${test.phpMethod}(): void\n    {\n        self::markTestIncomplete('Implement the upstream assertions.');\n    }`;
    }).join('\n\n');
    return `<?php\n\ndeclare(strict_types=1);\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class ${name} extends TestCase\n{\n${methods}\n}\n`;
}

/** A suite identifies the subject; other calls can merely prepare its fixtures. */
function suiteSubject(suites, typed, api, active) {
    for (let index = suites.length - 1; index >= 0; index--) {
        const label = suites[index];
        const labels = [label, index ? `${suites[index - 1]}.${label}` : label];
        const qualified = [...typed].filter(id => labels.some(value => value === id
            // Upstream names its HTTPRequest/HTTPResponse suites Request/Response.
            || (/^HTTP(Request|Response)\./.test(id) && value === id.slice(4))));
        const matches = qualified.length ? qualified
            : [...typed].filter(id => label === id.slice(id.lastIndexOf('.') + 1));
        if (matches.length === 1) return matches;
        // A shared method name is not enough to choose between two receivers.
        if (matches.length > 1) return [];
        const ignoredClass = api.classes.find(item => item.name === label
            && item.members.every(member => !active.has(member.id)));
        if (ignoredClass) {
            const subjects = ignoredClass.members.map(member => member.id).filter(id => typed.has(id));
            if (subjects.length) return subjects;
        }
    }
    return [];
}

/**
 * Discovery is conservative: typed calls support an exact public suite label;
 * names alone produce candidates, never proof. Explicit mappings resolve ambiguity.
 * Returns new stubs without writing; the caller creates absent files exclusively.
 */
function trackTests({root, sourceRoot, api, selection, config = {}, previous = null, upstreamLock = null,
    testIgnore = {schemaVersion: 1, tests: []}}) {
    root = path.resolve(root);
    sourceRoot = path.resolve(sourceRoot);
    const excluded = validateTestIgnore(testIgnore);
    const all = new Set(api.classes.flatMap(item => item.members.map(member => member.id)).concat(api.exports || []));
    const active = new Set(selection.activeClasses.flatMap(item => item.members.map(member => member.id))
        .concat(selection.activeExports || []));
    const diagnostics = [];
    const diagnostic = (code, testId, message, extra = {}) => diagnostics.push({code, componentId: 'tests', testId, message, ...extra});
    const mappings = config.testMappings || {};
    if (!mappings || typeof mappings !== 'object' || Array.isArray(mappings)) throw new Error('testMappings must be an object');
    const testRoots = config.testRoots || ['test/src'];
    const testFiles = [...new Set(testRoots.flatMap(relative => filesBelow(localPath(sourceRoot, relative))))]
        .filter(file => /\.(?:spec|test)\.[cm]?[jt]sx?$/.test(file)).sort();
    if (!testFiles.length) diagnostic('tests.source-empty', null, 'No upstream public test files were discovered');
    const program = ts.createProgram(testFiles, {allowJs: true, target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.NodeNext, moduleResolution: ts.ModuleResolutionKind.NodeNext,
        skipLibCheck: true, noEmit: true, baseUrl: root,
        paths: {'puppeteer-core': ['node_modules/puppeteer-core/lib/types.d.ts'], puppeteer: ['node_modules/puppeteer/lib/types.d.ts']}});
    const checker = program.getTypeChecker();
    const entries = [];
    const dependencyCache = new Map();
    const sharedDependencies = ['test/assets'].flatMap(relative => filesBelow(localPath(sourceRoot, relative)))
        .map(file => ({path: slash(path.relative(sourceRoot, file)), hash: hash(fs.readFileSync(file))}));
    const sharedFingerprint = hash(JSON.stringify(sharedDependencies));
    for (const file of testFiles) {
        const source = program.getSourceFile(file);
        if (!source) continue;
        const sourcePath = slash(path.relative(sourceRoot, file));
        const sourceDependencies = dependencies(file, sourceRoot, dependencyCache);
        function visit(node, suites = [], dynamicParent = false, skippedParent = false) {
            if (ts.isCallExpression(node)) {
                const kind = callKind(node.expression);
                if (kind) {
                    const titleNode = node.arguments[0];
                    const fixedTitle = titleNode && (ts.isStringLiteral(titleNode) || ts.isNoSubstitutionTemplateLiteral(titleNode));
                    const title = fixedTitle ? titleNode.text : `dynamic:${titleNode?.getText(source) || '<missing>'}`;
                    const callback = node.arguments.find(argument => ts.isArrowFunction(argument) || ts.isFunctionExpression(argument));
                    if (kind === 'describe') {
                        if (callback) visit(callback.body, [...suites, title], dynamicParent || !fixedTitle,
                            skippedParent || /\.(skip|todo)\b/.test(node.expression.getText()));
                        return;
                    }
                    const id = `${sourcePath}::${[...suites, title].join(' > ')}`;
                    const candidates = new Set();
                    const typed = new Set();
                    const labels = [...suites, ...suites.slice(1).map((label, index) => `${suites[index]}.${label}`)]
                        .filter(label => all.has(label));
                    function calls(child) {
                        if (ts.isCallExpression(child) && ts.isPropertyAccessExpression(child.expression)) {
                            const member = child.expression.name.text;
                            for (const publicId of all) if (publicId.endsWith(`.${member}`)) candidates.add(publicId);
                            const receiver = checker.getTypeAtLocation(child.expression.expression);
                            for (const type of receiver.isUnion() ? receiver.types : [receiver]) {
                                const name = type.getSymbol()?.getName();
                                const publicId = `${name}.${member}`;
                                if (name && all.has(publicId)) typed.add(publicId);
                            }
                        }
                        ts.forEachChild(child, calls);
                    }
                    if (callback) calls(callback.body);
                    labels.forEach(label => candidates.add(label));
                    const automatic = suiteSubject(suites, typed, api, active);
                    // Only cases with a public connection are in scope. Explicit mappings
                    // also support public tests whose behavior is hidden behind helpers.
                    if ([...candidates].some(candidate => active.has(candidate)) || automatic.length || mappings[id]) entries.push({id, origin: {path: sourcePath,
                        line: source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1},
                        suites, title, candidates: [...candidates].sort(), automatic,
                        dynamic: dynamicParent || !fixedTitle || node.expression.getText().includes('.each'),
                        upstreamSkipped: skippedParent || /\.(skip|todo)\b/.test(node.expression.getText()),
                        dependencies: sourceDependencies});
                    return;
                }
            }
            const dynamic = dynamicParent || ts.isForStatement(node) || ts.isForOfStatement(node)
                || ts.isForInStatement(node) || ts.isWhileStatement(node);
            ts.forEachChild(node, child => visit(child, suites, dynamic, skippedParent));
        }
        visit(source);
    }
    const counts = new Map();
    for (const entry of entries) counts.set(entry.id, (counts.get(entry.id) || 0) + 1);
    const occurrences = new Map();
    for (const entry of entries) {
        if (counts.get(entry.id) > 1) {
            const occurrence = (occurrences.get(entry.id) || 0) + 1;
            occurrences.set(entry.id, occurrence);
            entry.id += ` [variant ${occurrence}]`;
            entry.dynamic = true;
        }
    }
    for (const own of config.ownTests || []) {
        if (!own || typeof own.id !== 'string' || !own.id.startsWith('own:')) throw new Error('Own tests require an own: ID');
        entries.push({...own, origin: null, automatic: [], candidates: [], dependencies: [], own: true});
    }
    const knownIds = new Set(entries.map(entry => entry.id));
    if (knownIds.size !== entries.length) throw new Error('Duplicate scenario IDs');
    for (const id of excluded.keys()) if (!knownIds.has(id)) diagnostic('tests.stale-ignore', id, 'Excluded test no longer exists');
    for (const id of Object.keys(mappings)) if (!knownIds.has(id)) diagnostic('tests.stale-mapping', id, 'Mapped test no longer exists');
    const previousTests = new Map((previous?.tests || []).map(test => [test.id, test]));
    const stubs = [];
    const names = readableNames(entries, previousTests, mappings);
    const tests = [];
    for (const entry of entries.sort((a, b) => a.id.localeCompare(b.id))) {
        const mapping = entry.own ? entry : mappings[entry.id];
        const checkedApi = mapping?.api || (!entry.dynamic ? entry.automatic : []);
        if (!Array.isArray(checkedApi) || checkedApi.some(id => typeof id !== 'string')) throw new Error(`Invalid API mapping for ${entry.id}`);
        for (const id of checkedApi) if (!all.has(id)) diagnostic('tests.unknown-api', entry.id, `Unknown public API: ${id}`, {symbolId: id});
        const applicable = checkedApi.filter(id => active.has(id));
        const status = !checkedApi.length || checkedApi.some(id => !all.has(id)) ? 'unresolved'
            : !applicable.length ? 'excluded-api' : excluded.has(entry.id) ? 'excluded-test' : 'required';
        const previousTest = previousTests.get(entry.id);
        let naming = names.get(entry.id);
        if (mapping?.phpTest) naming = {phpTest: mapping.phpTest, phpMethod: mapping.phpMethod || null};
        else if (previousTest?.phpTest) {
            naming = {phpTest: previousTest.phpTest, phpMethod: previousTest.phpMethod || null};
        }
        const {phpTest, phpMethod} = naming;
        localPath(root, phpTest);
        const fixtures = mapping?.fixtures || [];
        if (!Array.isArray(fixtures) || fixtures.some(value => typeof value !== 'string')) throw new Error(`Invalid fixtures for ${entry.id}`);
        const projectDependencies = fixtures.map(relative => {
            const file = localPath(root, relative);
            if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
                diagnostic('tests.missing-fixture', entry.id, `Missing shared fixture: ${relative}`);
                return {path: relative, hash: null, project: true};
            }
            return {path: relative, hash: hash(fs.readFileSync(file)), project: true};
        });
        const deps = [...entry.dependencies, ...projectDependencies].sort((a, b) => a.path.localeCompare(b.path));
        const fingerprint = hash(JSON.stringify({dependencies: deps, sharedFingerprint, api: checkedApi, phpTest, phpMethod}));
        const change = !previousTest ? 'added' : previousTest.fingerprint !== fingerprint ? 'changed' : 'unchanged';
        const test = {id: entry.id, api: checkedApi, candidates: entry.candidates, origin: entry.origin,
            status, reason: status === 'excluded-test' ? excluded.get(entry.id)
                : status === 'excluded-api' ? `API excluded: ${checkedApi.join(', ')}` : null,
            phpTest, phpMethod, upstreamUrl: githubLink(upstreamLock, entry.origin), fixtures,
            dependencies: deps, fingerprint, change,
            upstreamSkipped: !!entry.upstreamSkipped};
        if (status === 'unresolved') diagnostic('tests.unresolved', entry.id,
            entry.dynamic ? 'Dynamic or repeated scenario requires an explicit mapping' : 'Public API association requires a typed subject or explicit mapping');
        if (change === 'changed') diagnostic('tests.changed', entry.id, 'Upstream scenario or shared fixtures changed; preserve and review PHP assertions');
        tests.push(test);
    }
    const groups = new Map();
    for (const test of tests.filter(test => test.status === 'required')) {
        if (!groups.has(test.phpTest)) groups.set(test.phpTest, []);
        groups.get(test.phpTest).push(test);
    }
    const requests = [];
    for (const [phpTest, group] of groups) {
        const destination = localPath(root, phpTest);
        const source = fs.existsSync(destination) ? fs.readFileSync(destination, 'utf8') : null;
        if (group.some(test => !test.phpMethod)) {
            if (group.some(test => test.phpMethod)) throw new Error(`Mixed file and method test mappings require explicit phpMethod: ${phpTest}`);
            if (source === null) stubs.push({path: phpTest, content: stubFor(group[0].id, phpTest, group[0].upstreamUrl)});
        } else requests.push({path: phpTest, source, template: groupedStub(phpTest, group), trusted: group.filter(test => { const old = previousTests.get(test.id); const explicit = mappings[test.id] || (config.ownTests || []).find(own => own.id === test.id); return (old?.phpTest === phpTest && old?.phpMethod === test.phpMethod) || (explicit?.phpTest === phpTest && explicit?.phpMethod === test.phpMethod); }).map(test => test.phpMethod)});
    }
    if (requests.length) {
        const process = spawnSync('php', [path.resolve(__dirname, '../php/test-stubs.php')], {input: JSON.stringify(requests), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});
        if (process.error || process.status !== 0) throw new Error(`PHP test synchronization failed: ${process.error?.message || process.stderr || process.stdout}`);
        for (const result of JSON.parse(process.stdout)) {
            if (result.content !== null) stubs.push({path: result.path, content: result.content});
        }
    }
    for (const old of previousTests.values()) if (!knownIds.has(old.id)) {
        diagnostic('tests.removed', old.id, 'Upstream scenario removed; existing PHP code is preserved', {phpPath: old.phpTest || old.php});
    }
    for (const id of [...active].sort()) {
        if (!tests.some(test => test.status === 'required' && test.api.includes(id))) {
            diagnostics.push({code: 'tests.uncovered-api', componentId: 'tests', symbolId: id,
                message: 'No required scenario covers this active API'});
        }
    }
    diagnostics.sort((a, b) => `${a.testId || a.symbolId || ''}:${a.code}`.localeCompare(`${b.testId || b.symbolId || ''}:${b.code}`));
    return {catalog: {schemaVersion: 1, sharedDependencies, sharedFingerprint, tests}, diagnostics, stubs};
}

module.exports = {trackTests, validateTestIgnore, localPath};
