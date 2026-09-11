'use strict';
const {createTypeMapper, withoutUndefined} = require('./type-mapper.cjs');
const {diagnostic} = require('./model.cjs');

/** Generate only contracts the async bridge can represent; report every omission. */
function buildPhpModel({api, config}) {
    const diagnostics = [];
    const report = (code, id, expected, actual) => diagnostics.push(diagnostic(code, 'QuickJsModel', id, expected, actual));
    const selected = new Set(config.wrapperClasses);
    const ids = new Set(api.classes.flatMap(c => c.members.map(m => m.id)));
    for (const name of selected) if (!api.classes.some(c => c.name === name)) report('class.removed', name, 'Upstream class', 'No longer exported; wrapper will be removed');
    for (const id of Object.keys(config.members || {})) if (!ids.has(id)) report('member.removed', id, 'Upstream member', 'No longer present; mapping ignored');
    const classMap = new Map(api.classes.filter(c => selected.has(c.name)).map(c => [c.name, {fqcn: `${config.namespace}\\${c.name}`, file: `src/${c.name}.php`}]));
    const mapper = createTypeMapper({types: api.types, config, classMap, report, requireClass: () => {}});
    const classes = [], coverage = [];
    for (const item of api.classes) {
        const output = {name: item.name, ...classMap.get(item.name), members: [], generatedDocLines: [], ...(item.name === 'ElementHandle' ? {extends: `${config.namespace}\\JSHandle`} : {})};
        for (const member of item.members) {
            const start = diagnostics.length;
            const rule = config.members?.[member.id] || {};
            const omit = reason => {report('member.unsupported', member.id, 'Supported async bridge contract', reason);};
            if (!selected.has(item.name)) omit('Class is not yet supported by typed hydration');
            else if (!member.static && member.kind === 'property' && member.readonly) {
                const returns = mapper.map(member.type, member.id, {templates:new Map()});
                if (diagnostics.length === start) output.members.push({id:member.id,name:member.name,kind:'property',type:returns.native,docType:returns.psalm});
            }
            else if (member.static || member.kind !== 'method') omit('Constructors, static methods and writable properties need separate support');
            else if (rule.unsupported) omit(rule.unsupported);
            else {
                const name = rule.name || member.name;
                if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(name) || ['release', 'invokeRemote', 'mergeNamedArguments', 'getRemote', 'remoteId', '__construct', '__call', '__get'].includes(name)) omit('PHP name requires an explicit safe mapping');
                else if (member.signatures.length > 1 && rule.overload === undefined) omit('Overloads require explicit representation');
                else {
                    const sig = member.signatures[rule.overload || 0];
                    if (!sig) throw new Error(`Invalid overload for ${member.id}`);
                    const templates = new Map([...(item.typeParameters || []), ...(sig.typeParameters || [])].map(t => [t.name, {...t, substitution: {text:'unknown'}}]));
                    const map = type => mapper.map(type, member.id, {templates});
                    const parameters = sig.parameters.map((p, i) => {
                        const override = rule.parameters?.[i];
                        const t = override || map(p.optional ? withoutUndefined(p.type) : p.type);
                        const param = {name:p.name, type:t.native, docType:t.psalm, optional:!!p.optional, variadic:!!p.rest};
                        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(p.name)) omit('Destructured parameter requires explicit mapping');
                        if (p.rest) {
                            if (t.native === 'array' && /^list<.*>$/s.test(t.psalm)) {param.type='mixed';param.docType=t.psalm.slice(5,-1);}
                            else if (!override) omit('Variadic tuple cannot be represented safely');
                        } else if (p.optional) {
                            param.default = null;
                            if (param.type !== 'mixed' && !param.type.split('|').includes('null')) param.type += '|null';
                            if (param.docType !== 'mixed') param.docType = `(${param.docType})|null`;
                        }
                        return param;
                    });
                    const returns = rule.returnType || map(sig.returnType);
                    const doc = returns.psalm === 'void' ? 'null' : returns.psalm;
                    if (diagnostics.length === start) output.members.push({id:member.id, name, jsName:member.name, kind:'method', static:false, parameters,
                        returnType:returns.native.includes('|') ? returns.native.replace(/\bvoid\b/g, 'null') : returns.native, returnDocType:returns.psalm.includes('|') ? returns.psalm.replace(/\bvoid\b/g, 'null') : returns.psalm, generatedDocLines:[]});
                }
            }
            coverage.push({id:member.id, status:diagnostics.length === start ? 'generated' : 'unsupported', diagnostics:diagnostics.slice(start)});
        }
        if (selected.has(item.name)) classes.push(output);
    }
    for (const name of api.exports) report('export.unsupported', name, 'Explicit PHP root mapping', 'Root exports are not remote-object methods');
    return {classes, diagnostics, coverage};
}
module.exports = {buildPhpModel};
