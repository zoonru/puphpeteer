'use strict';

const fs = require('node:fs');
const path = require('node:path');

function readJson(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }
function json(value) { return JSON.stringify(value, null, 2) + '\n'; }
function object(value, label) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`${label} must be an object`);
}
function fields(value, allowed, label) {
    object(value, label);
    for (const key of Object.keys(value)) if (!allowed.includes(key)) throw new Error(`Unknown ${label} field: ${key}`);
}
function inside(root, relative) {
    if (typeof relative !== 'string' || !relative || path.isAbsolute(relative)) throw new Error(`Expected relative path: ${relative}`);
    const resolved = path.resolve(root, relative);
    if (resolved === root || !resolved.startsWith(path.resolve(root) + path.sep)) throw new Error(`Path escapes project: ${relative}`);
    // Do not follow a project symlink into an unrelated working directory when publishing.
    for (let current = resolved; current !== path.resolve(root); current = path.dirname(current)) {
        if (fs.existsSync(current) && fs.lstatSync(current).isSymbolicLink()) throw new Error(`Symlink in managed path: ${relative}`);
    }
    return resolved;
}
function validateConfig(config) {
    fields(config, ['schemaVersion', 'namespace', 'wrapperClasses', 'members', 'types'], 'config');
    if (config.schemaVersion !== 1) throw new Error('Unsupported config schema');
    if (config.namespace !== 'Nesk\\Puphpeteer') throw new Error('Invalid PHP namespace');
    if (!Array.isArray(config.wrapperClasses) || !config.wrapperClasses.every(name => /^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) || new Set(config.wrapperClasses).size !== config.wrapperClasses.length) throw new Error('Invalid wrapperClasses');
    for (const key of ['members', 'types']) object(config[key] || {}, `config.${key}`);
    for (const [name, value] of Object.entries(config.types || {})) {
        fields(value, ['native', 'psalm'], `types.${name}`);
        if (typeof value.native !== 'string' || typeof value.psalm !== 'string') throw new Error(`Invalid type mapping: ${name}`);
    }
    for (const [name, value] of Object.entries(config.members || {})) {
        fields(value, ['name', 'overload', 'parameters', 'returnType', 'unsupported'], `members.${name}`);
        if (value.overload !== undefined && (!Number.isInteger(value.overload) || value.overload < 0)) throw new Error(`Invalid overload mapping: ${name}`);
        if (value.parameters !== undefined && !Array.isArray(value.parameters)) throw new Error(`Invalid parameters mapping: ${name}`);
    }
    return config;
}
function diagnostic(code, componentId, symbolId, expected, actual, extra = {}) {
    return Object.fromEntries(Object.entries({code, componentId, symbolId: symbolId || null, expected, actual, ...extra}).filter(([, value]) => value != null));
}
function sortDiagnostics(values) {
    return values.sort((a, b) => json(a).localeCompare(json(b), 'en'));
}

module.exports = {readJson, json, inside, validateConfig, diagnostic, sortDiagnostics};
