'use strict';

const {inside, diagnostic} = require('./model.cjs');
const {createTypeMapper, withoutUndefined} = require('./type-mapper.cjs');

/** Convert declarations only. Unknown TS constructs are diagnostics, never inferred implementations. */
function buildPhpModel({root, api, selection, config, links = new Map()}) {
    const diagnostics = [];
    const sourceDoc = id => links.has(id) ? [`@see ${links.get(id)} Upstream`] : [];
    const active = new Set(selection.activeClasses.map(item => item.name));
    const classMap = new Map(api.classes.map(item => [item.name, {
        fqcn: `${config.namespace}\\${item.name}`,
        file: `src/${item.name}.php`,
        ...(config.classes || {})[item.name],
    }]));
    const requiredTypes = new Set();
    const report = (code, id, expected, actual) => diagnostics.push(diagnostic(code, 'PhpTypeMapper', id, expected, actual));
    // Interfaces with behavior need nominal declarations; data interfaces remain array shapes.
    const behavioral = (api.types || []).filter(type => type.kind === 'interface' && type.members?.some(member => member.kind === 'method'));
    for (const type of behavioral) classMap.set(type.name, {fqcn: `${config.namespace}\\${type.name}`, file: `src/${type.name}.php`, ...(config.classes || {})[type.name]});
    const mapper = createTypeMapper({types: api.types || [], config, classMap, report,
        requireClass: name => { if (!active.has(name)) requiredTypes.add(name); }});
    const mapType = (type, id, templates = []) => mapper.map(type, id, {templates: new Map(templates.map(template => [template.name, template]))});
    const makeClass = item => {
        const fullClass = api.classes.find(candidate => candidate.name === item.name) || item;
        const mapping = classMap.get(item.name);
        inside(root, mapping.file);
        const members = item.members.map(member => {
            const rule = (config.members || {})[member.id] || {};
            let name = rule.name || member.name || member.id.split(/::|\./).pop();
            if (member.kind === 'constructor') name = '__construct';
            if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(name)) {
                report('member.name', member.id, 'Explicit PHP member name', name);
                return null;
            }
            const kind = rule.kind || member.kind;
            if (member.accessor && !rule.kind) {
                report('member.accessor', member.id, 'Explicit getter/setter mapping', member.accessor);
                return null;
            }
            if (kind === 'property') {
                const type = mapType(member.optional ? withoutUndefined(member.type) : member.type, member.id, fullClass.typeParameters);
                return {id: member.id, name, kind, static: member.static || false, type: type.native.split('|').includes('callable') || type.native === 'null' ? 'mixed' : type.native, docType: type.psalm, generatedDocLines: sourceDoc(member.id)};
            }
            const signatures = member.signatures || [];
            if (!signatures.length) {
                report('signature.missing', member.id, 'At least one public signature', null);
                return null;
            }
            const index = rule.overload ?? 0;
            if (signatures.length > 1 && rule.overload === undefined) report('signature.overload', member.id, 'Explicit overload representation in config.members', signatures.length);
            if (!signatures[index]) {
                report('signature.overload-index', member.id, `Index < ${signatures.length}`, index);
                return null;
            }
            const signature = signatures[index];
            const templates = [...(fullClass.typeParameters || []), ...(signature.typeParameters || [])];
            const parameters = signature.parameters.map((parameter, index) => {
                const paramRule = rule.parameters?.[index] || {};
                const type = paramRule.type ? {native: paramRule.type, psalm: paramRule.docType || paramRule.type} : mapType(parameter.optional ? withoutUndefined(parameter.type) : parameter.type, member.id, templates);
                const result = {name: paramRule.name || parameter.name, type: type.native === 'null' ? 'mixed' : type.native, docType: type.psalm,
                    optional: Boolean(parameter.optional), variadic: Boolean(parameter.rest)};
                if (result.variadic && type.native === 'array') {
                    result.type = 'mixed';
                    if (/^list<.*>$/s.test(type.psalm)) result.docType = type.psalm.slice(5, -1);
                    else {
                        report('parameter.variadic', member.id, 'Explicit representation of a variadic tuple/template', parameter.type.text);
                        result.docType = 'mixed';
                    }
                }
                if ('default' in paramRule) result.default = paramRule.default;
                else if (result.optional && !result.variadic) {
                    report('parameter.default', member.id, 'Explicit default preserving undefined/omission semantics', parameter.name);
                    result.optional = false; // A required scaffold is explicit debt, never an invented null default.
                }
                return result;
            });
            const result = rule.returnType ? {native: rule.returnType.native, psalm: rule.returnType.psalm} : mapType(signature.returnType, member.id, templates);
            return {id: member.id, name, kind: kind === 'constructor' ? 'constructor' : 'method', static: rule.static ?? member.static ?? false,
                parameters, ...(name === '__construct' ? {} : {returnType: result.native === 'null' ? 'mixed' : result.native, returnDocType: result.psalm}),
                generatedDocLines: [...(rule.templates || signature.typeParameters || []).map(template => templateLine(template, member.id, templates)), ...sourceDoc(member.id)]};
        }).filter(Boolean);
        return {name: item.name, ...mapping, members, generatedDocLines: [...(fullClass.typeParameters || []).map(template => templateLine(template, item.name, fullClass.typeParameters)), ...sourceDoc(`class:${item.name}`)]};
    };
    const classes = selection.activeClasses.map(makeClass);
    for (const name of requiredTypes) {
        const definition = behavioral.find(type => type.name === name);
        classes.push(makeClass(definition || {...api.classes.find(type => type.name === name), members: []}));
    }
    const destinations = new Map();
    for (const item of classes) {
        for (const key of [item.file.toLowerCase(), item.fqcn.toLowerCase()]) {
            if (destinations.has(key)) report('mapping.ambiguous', item.name, 'Unique PHP class and file', destinations.get(key));
            destinations.set(key, item.name);
        }
        const members = new Set();
        for (const member of item.members) {
            const key = `${member.kind === 'property' ? '$' : ''}${member.name.toLowerCase()}`;
            if (members.has(key)) report('mapping.ambiguous', member.id, 'Unique PHP member', key);
            members.add(key);
        }
    }
    for (const name of selection.activeExports) report('export.unsupported', name, 'Explicit PHP runtime export mapping', name);
    function templateLine(value, id, templates) {
        const constraint = value.constraint && (typeof value.constraint === 'string' ? value.constraint : mapType(value.constraint, id, templates).psalm);
        return '@template ' + value.name + (constraint && constraint !== 'mixed' ? ' as ' + constraint : '');
    }
    return {classes, diagnostics, requiredTypes: [...requiredTypes].sort(), knownMemberIds: [...api.classes, ...behavioral].flatMap(item => item.members.map(member => member.id))};
}
module.exports = {buildPhpModel};
