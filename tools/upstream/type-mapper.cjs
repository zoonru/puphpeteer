'use strict';

const result = (native, psalm = native) => ({native, psalm});
const union = values => {
    const types = [...new Set(values.flatMap(value => value.split('|')))];
    if (types.includes('mixed')) return 'mixed';
    if (types.length > 1 && types.some(type => type.includes('&'))) return types.every(type => type.split('&').every(part => part.startsWith('\\'))) ? 'object' : 'mixed';
    if (types.includes('bool')) return types.filter(type => !['true', 'false'].includes(type)).join('|');
    return types.join('|');
};
const withoutUndefined = type => type?.kind === 'union'
    ? {...type, types: (type.types || type.children).filter(item => item.kind !== 'undefined' && item.text !== 'undefined')}
    : type;

/** Structural TS → PHP declaration mapping. Only genuine any/unknown become mixed. */
function createTypeMapper({types, config, classMap, requireClass, report}) {
    const definitions = new Map(types.map(type => [type.name, type]));
    function shape(properties, indices, id, context) {
        const merged = new Map();
        for (const property of properties) {
            const previous = merged.get(property.name);
            merged.set(property.name, previous ? {...property, optional: previous.optional && property.optional,
                type: {kind: 'intersection', types: [previous.optional ? withoutUndefined(previous.type) : previous.type, property.optional ? withoutUndefined(property.type) : property.type]}} : property);
        }
        const fields = [...merged.values()].map(property => {
            const type = map(property.optional ? withoutUndefined(property.type) : property.type, id, context);
            const key = /^[A-Za-z_][A-Za-z0-9_]*$/.test(property.name) ? property.name : JSON.stringify(property.name);
            return `${key}${property.optional ? '?' : ''}: ${type.psalm}`;
        });
        const indexTypes = indices.map(index => ({key: map(index.keyType, id, context), value: map(index.type, id, context)}));
        if (!fields.length && indexTypes.length === 1) return result('array', `array<${indexTypes[0].key.psalm}, ${indexTypes[0].value.psalm}>`);
        if (indexTypes.length) {
            const keys = union(indexTypes.map(index => index.key.psalm));
            const values = docUnion(indexTypes.map(index => index.value.psalm));
            fields.push(`...<${keys}, ${values}>`);
        }
        return result('array', `array{${fields.join(', ')}}`);
    }
    function map(type, id, context = {}) {
        context = {seen: new Set(), templates: new Map(), ...context};
        if (!type) return unsupported(id, type, 'type.unresolved');
        if (typeof type === 'string') type = {text: type};
        const text = type.text;
        if (config.types?.[text]) return config.types[text];
        const primitive = {string: 'string', boolean: 'bool', number: 'int|float', null: 'null', void: 'void', never: 'never', any: 'mixed', unknown: 'mixed', object: 'object'};
        if (primitive[text]) return result(primitive[text]);
        if (text === 'true' || text === 'false') return result('bool', text);
        if (type.kind === 'literal' || /^(['"]).*\1$/.test(text) || /^-?\d+(?:\.\d+)?$/.test(text)) {
            if (Object.hasOwn(type, 'value') && !['string', 'number', 'boolean'].includes(typeof type.value)) return unsupported(id, type);
            const literal = Object.hasOwn(type, 'value') ? JSON.stringify(type.value) : text;
            if (typeof type.value === 'boolean') return result('bool', literal);
            return result(typeof type.value === 'number' || /^-?\d/.test(literal) ? (literal.includes('.') ? 'float' : 'int') : 'string', literal);
        }
        if (type.kind === 'typeParameter') {
            const template = context.templates.get(text);
            if (!template) return unsupported(id, type, 'type.unbound-template');
            if (template.substitution) return map(template.substitution, id, context);
            return result(template.constraint ? map(template.constraint, id, {...context, templates: new Map([...context.templates].filter(([name]) => name !== text))}).native : 'mixed', text);
        }
        const children = type.types || type.children;
        if (type.kind === 'union' && children?.length) {
            const parts = children.map(child => map(child, id, context));
            return result(union(parts.map(part => part.native)), docUnion(parts.map(part => part.psalm)));
        }
        if (type.kind === 'intersection' && children?.length) {
            const structures = children.map(child => structure(child, context));
            if (structures.every(Boolean)) return shape(structures.flatMap(item => item.properties), structures.flatMap(item => item.indices), id, context);
            const parts = children.map(child => map(child, id, context));
            if (parts.every(part => part.psalm === parts[0].psalm)) return parts[0];
            if (parts.every(part => ['string', 'int', 'float', 'bool', 'null', 'never'].includes(part.native)) && new Set(parts.map(part => part.native)).size > 1) return result('never');
            if (parts.every(part => /^\\[A-Za-z_\\]+$/.test(part.native))) return result(parts.map(part => part.native).join('&'), parts.map(part => part.psalm).join('&'));
            return unsupported(id, type, 'type.intersection');
        }
        const args = type.typeArguments || [];
        const name = type.name || text?.replace(/<.*$/s, '');
        if (['Promise', 'PromiseLike'].includes(name) && args.length) {
            const value = map(args[0], id, context);
            return value;
        }
        if (name === 'Awaited' && args.length) {
            const argument = args[0];
            if (argument.kind === 'typeParameter') {
                const constraint = context.templates.get(argument.text)?.constraint;
                if (!constraint || !['string', 'number', 'boolean', 'null'].includes(constraint.kind || constraint.text)) return unsupported(id, type, 'type.utility');
            }
            return map(awaited(argument), id, context);
        }
        if (name === 'ReturnType' && args.length) {
            if (args[0].kind === 'typeParameter' && !context.templates.get(args[0].text)?.substitution) return unsupported(id, type, 'type.utility');
            const argument = args[0].kind === 'typeParameter' ? context.templates.get(args[0].text).substitution : args[0];
            const callable = resolve(argument, context);
            if (callable?.signatures?.length === 1) return map(callable.signatures[0].returnType, id, context);
            return unsupported(id, type);
        }
        if (type.elementType || ['Array', 'ReadonlyArray'].includes(name) && args.length) {
            return result('array', `list<${map(type.elementType || args[0], id, context).psalm}>`);
        }
        if (type.kind === 'tuple') {
            const fields = (type.elements || args).map((element, index) => {
                const flag = type.elementFlags?.[index] || 1;
                const mapped = map(flag & 2 ? withoutUndefined(element) : element, id, context).psalm;
                return flag & 12 ? `...<int, ${mapped}>` : `${index}${flag & 2 ? '?' : ''}: ${mapped}`;
            });
            return result('array', `array{${fields.join(', ')}}`);
        }
        if (classMap.has(name)) {
            requireClass(name);
            const fqcn = '\\' + classMap.get(name).fqcn;
            return result(fqcn); // Runtime wrappers erase browser DOM generic parameters.
        }
        if (type.kind === 'function') {
            if (type.signatures?.length !== 1) return unsupported(id, type, 'signature.overload');
            const signature = type.signatures[0];
            const parameters = signature.parameters.map(parameter => {
                const value = map(parameter.optional ? withoutUndefined(parameter.type) : parameter.type, id, context);
                const doc = parameter.rest && /^list<.*>$/.test(value.psalm) ? value.psalm.slice(5, -1) : value.psalm;
                return `${doc}${parameter.rest ? '...' : ''}${parameter.optional ? '=' : ''}`;
            });
            const returns = map(signature.returnType, id, context);
            return result('\\Closure', `\\Closure(${parameters.join(', ')}):${parenthesize(returns.psalm)}`);
        }
        if (type.properties || type.indexSignatures) return shape(type.properties || [], type.indexSignatures || [], id, context);
        const definition = definitions.get(name);
        if (definition) {
            if (context.seen.has(name)) return unsupported(id, type, 'type.recursive');
            const next = {...context, seen: new Set([...context.seen, name]), templates: new Map(context.templates)};
            for (const [index, template] of (definition.typeParameters || []).entries()) {
                next.templates.set(template.name, {substitution: args[index] || template.default || template.constraint || {text: 'unknown'}});
            }
            if (definition.type) return map(definition.type, id, next);
            if (definition.kind === 'enum') return map({kind: 'union', types: definition.values.map(item => ({kind: 'literal', text: JSON.stringify(item.value), value: item.value}))}, id, next);
            if (definition.signatures?.length) return map({kind: 'function', signatures: definition.signatures, text}, id, next);
            if (definition.members?.every(member => member.kind === 'property')) return shape(definition.members, definition.indexSignatures || [], id, next);
            return unsupported(id, type);
        }
        return unsupported(id, type);
    }
    function unsupported(id, type, code = 'type.unsupported') {
        report(code, id, 'Explicit native/Psalm representation', type?.text ?? null);
        return result('mixed');
    }
    function resolve(type, context) {
        if (!type) return null;
        return definitions.get(type.name || type.text)?.type || type;
    }
    function substitute(type, context) {
        if (!type || typeof type !== 'object') return type;
        if (type.kind === 'typeParameter' && context.templates.get(type.text)?.substitution) return context.templates.get(type.text).substitution;
        if (Array.isArray(type)) return type.map(item => substitute(item, context));
        return Object.fromEntries(Object.entries(type).map(([key, value]) => [key, substitute(value, context)]));
    }
    function structure(type, context, seen = new Set()) {
        if (!type) return null;
        if (type.properties || type.indexSignatures) return {properties: substitute(type.properties || [], context), indices: substitute(type.indexSignatures || [], context)};
        const name = type.name || type.text;
        if (seen.has(name)) return null;
        seen.add(name);
        const definition = definitions.get(name);
        const next = {...context, templates: new Map(context.templates)};
        for (const [index, parameter] of (definition?.typeParameters || []).entries()) {
            next.templates.set(parameter.name, {substitution: substitute(type.typeArguments?.[index] || parameter.default || parameter.constraint || {text: 'unknown'}, context)});
        }
        if (definition?.type) return structure(definition.type, next, seen);
        if (definition?.members?.every(member => member.kind === 'property')) return {properties: substitute(definition.members, next), indices: substitute(definition.indexSignatures || [], next)};
        return null;
    }
    function awaited(type) {
        return ['Promise', 'PromiseLike', 'Awaited'].includes(type.name) && type.typeArguments?.length ? awaited(type.typeArguments[0]) : type;
    }
    return {map, withoutUndefined};
}
const parenthesize = text => text.includes('|') || text.includes('&') ? `(${text})` : text;
function docUnion(values) {
    const unique = [...new Set(values)];
    if (unique.includes('mixed')) return 'mixed';
    if (unique.includes('true') && unique.includes('false')) return docUnion([...unique.filter(value => !['true', 'false'].includes(value)), 'bool']);
    return unique.map(value => value.startsWith('callable(') || /^\\[^<{}()]+&/.test(value) ? `(${value})` : value).join('|');
}
module.exports = {createTypeMapper, withoutUndefined};
