'use strict';

const path = require('node:path');
const ts = require('typescript');

const compare = (a, b) => a < b ? -1 : a > b ? 1 : 0;
const sorted = values => [...new Set(values)].sort(compare);

function isPublic(node) {
    return !(ts.getCombinedModifierFlags(node) & (ts.ModifierFlags.Private | ts.ModifierFlags.Protected))
        && !(node.name && ts.isPrivateIdentifier(node.name))
        && !ts.getJSDocTags(node).some(tag => tag.tagName.text === 'internal');
}

function memberName(node) {
    if (ts.isConstructorDeclaration(node)) return 'constructor';
    return ts.isComputedPropertyName(node.name) ? node.name.getText() : node.name.text;
}

/** Extract the external declaration contract. No implementation dependency traversal. */
function extractPublicApi(entry, options = {}) {
    entry = path.resolve(entry);
    const root = path.resolve(options.root || path.dirname(entry));
    const program = ts.createProgram([entry], {
        target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.NodeNext,
        moduleResolution: ts.ModuleResolutionKind.NodeNext, skipLibCheck: false,
        strictNullChecks: true, noEmit: true,
    });
    const errors = program.getSyntacticDiagnostics();
    if (errors.length) throw new Error(ts.formatDiagnostics(errors, {
        getCanonicalFileName: file => file, getCurrentDirectory: () => root, getNewLine: () => '\n',
    }));
    const checker = program.getTypeChecker();
    const sourceFile = program.getSourceFile(entry);
    const moduleSymbol = sourceFile && checker.getSymbolAtLocation(sourceFile);
    if (!moduleSymbol) throw new Error(`Cannot resolve public module: ${entry}`);
    const diagnostics = [];
    const queuedTypes = new Map();
    const exportedNames = new Map();
    const typeFlags = ts.TypeFormatFlags.NoTruncation | ts.TypeFormatFlags.UseAliasDefinedOutsideCurrentScope;
    const relative = filename => {
        const normalized = filename.split(path.sep).join('/');
        const dependency = normalized.lastIndexOf('/node_modules/');
        return dependency >= 0 ? normalized.slice(dependency + 1) : path.relative(root, filename).split(path.sep).join('/');
    };
    const source = node => ({path: relative(node.getSourceFile().fileName), line: node.getSourceFile().getLineAndCharacterOfPosition(node.getStart()).line + 1});
    const resolve = symbol => symbol && (symbol.flags & ts.SymbolFlags.Alias ? checker.getAliasedSymbol(symbol) : symbol);
    const publicExports = checker.getExportsOfModule(moduleSymbol).map(exported => ({exported, symbol: resolve(exported)}));
    for (const {exported, symbol} of publicExports) {
        if (!exportedNames.has(symbol)) exportedNames.set(symbol, exported.name);
    }
    function reference(symbol) {
        symbol = resolve(symbol);
        if (!symbol || symbol.flags & ts.SymbolFlags.TypeParameter) return null;
        const nodes = symbol.getDeclarations() || [];
        const node = nodes.find(item => ts.isInterfaceDeclaration(item) || ts.isTypeAliasDeclaration(item) || ts.isEnumDeclaration(item) || ts.isClassDeclaration(item));
        if (!node || !isPublic(node)) return null;
        const name = exportedNames.get(symbol) || checker.getFullyQualifiedName(symbol).replace(/^\"[^\"]+\"\./, '');
        // Built-in/library types have representations, but never generate their implementation classes.
        if (!program.isSourceFileDefaultLibrary(node.getSourceFile()) && !queuedTypes.has(symbol)) queuedTypes.set(symbol, name);
        return name;
    }
    function refsIn(node) {
        const refs = [];
        if (!node) return refs;
        function visit(child) {
            if (!isPublic(child)) return;
            if (ts.isTypeReferenceNode(child) || ts.isExpressionWithTypeArguments(child)) {
                const name = reference(checker.getSymbolAtLocation(child.typeName || child.expression));
                if (name) refs.push(name);
            }
            ts.forEachChild(child, visit);
        }
        visit(node);
        return sorted(refs);
    }
    function typeModel(type, node, seen = new Set()) {
        const text = checker.typeToString(type, node, typeFlags);
        const model = {kind: 'reference', text, refs: []};
        const name = reference(type.aliasSymbol || type.symbol);
        if (name) { model.name = name; model.refs.push(name); }
        if (seen.has(type)) return model;
        seen = new Set(seen).add(type);
        const nested = child => typeModel(child, undefined, seen);
        if (type.aliasTypeArguments?.length) model.typeArguments = type.aliasTypeArguments.map(nested);
        if (type.flags & ts.TypeFlags.Any) model.kind = 'any';
        else if (type.flags & ts.TypeFlags.Unknown) model.kind = 'unknown';
        else if (type.flags & ts.TypeFlags.Never) model.kind = 'never';
        else if (type.flags & ts.TypeFlags.Void) model.kind = 'void';
        else if (type.flags & ts.TypeFlags.Undefined) model.kind = 'undefined';
        else if (type.flags & ts.TypeFlags.Null) model.kind = 'null';
        else if (type.flags & ts.TypeFlags.StringLiteral) { model.kind = 'literal'; model.value = type.value; }
        else if (type.flags & ts.TypeFlags.NumberLiteral) { model.kind = 'literal'; model.value = type.value; }
        else if (type.flags & ts.TypeFlags.BooleanLiteral) { model.kind = 'literal'; model.value = type.intrinsicName === 'true'; }
        else if (type.flags & ts.TypeFlags.String) model.kind = 'string';
        else if (type.flags & ts.TypeFlags.Number) model.kind = 'number';
        else if (type.flags & ts.TypeFlags.Boolean) model.kind = 'boolean';
        else if (type.flags & ts.TypeFlags.BigIntLike) model.kind = 'bigint';
        else if (type.flags & ts.TypeFlags.ESSymbolLike) model.kind = 'symbol';
        else if (type.flags & ts.TypeFlags.TypeParameter) model.kind = 'typeParameter';
        else if (type.isUnionOrIntersection()) {
            model.kind = type.isUnion() ? 'union' : 'intersection';
            model.types = type.types.map(nested);
        } else if (type.flags & ts.TypeFlags.Conditional) model.kind = 'conditional';
        else if (type.flags & ts.TypeFlags.IndexedAccess) model.kind = 'indexedAccess';
        else if (type.flags & ts.TypeFlags.Index) model.kind = 'keyof';
        else if (type.flags & ts.TypeFlags.TemplateLiteral) model.kind = 'templateLiteral';
        else if (type.flags & ts.TypeFlags.Object) {
            const args = type.aliasTypeArguments || (type.objectFlags & ts.ObjectFlags.Reference ? checker.getTypeArguments(type) : []);
            if (args.length) model.typeArguments = args.map(nested);
            if (checker.isArrayType(type)) { model.kind = 'array'; model.elementType = nested(checker.getTypeArguments(type)[0]); }
            else if (checker.isTupleType(type)) { model.kind = 'tuple'; model.elements = args.map(nested); model.elementFlags = type.target.elementFlags; }
            else if (!name || type.objectFlags & ts.ObjectFlags.Mapped || (type.aliasSymbol && node && (ts.isTypeLiteralNode(node) || ts.isFunctionTypeNode(node)))) {
                const signatures = checker.getSignaturesOfType(type, ts.SignatureKind.Call);
                if (signatures.length) { model.kind = 'function'; model.signatures = signatures.map(item => signatureModel(item, seen)); }
                else {
                    model.kind = type.objectFlags & ts.ObjectFlags.Mapped ? 'mapped' : 'object';
                    model.properties = checker.getPropertiesOfType(type).filter(property => !(property.declarations || []).length || property.declarations.some(isPublic)).map(property => {
                        const declaration = property.valueDeclaration || property.declarations?.[0] || node;
                        return {name: property.name, optional: !!(property.flags & ts.SymbolFlags.Optional), type: nested(checker.getTypeOfSymbolAtLocation(property, declaration))};
                    });
                    model.indexSignatures = checker.getIndexInfosOfType(type).map(index => ({keyType: nested(index.keyType), type: nested(index.type), readonly: index.isReadonly}));
                }
            }
        }
        model.refs = sorted([...model.refs, ...refsIn(node && ts.isTypeNode(node) ? node : undefined),
            ...(model.types || []), ...(model.typeArguments || []), ...(model.elements || []),
            ...(model.properties || []).map(item => item.type), ...(model.signatures || []).flatMap(item => [...item.parameters.map(parameter => parameter.type), item.returnType]),
        ].flatMap(item => typeof item === 'string' ? [item] : item.refs));
        return model;
    }
    function typeParameters(nodes) {
        return (nodes || []).map(node => ({name: node.name.text,
            ...(node.constraint ? {constraint: typeModel(checker.getTypeAtLocation(node.constraint), node.constraint)} : {}),
            ...(node.default ? {default: typeModel(checker.getTypeAtLocation(node.default), node.default)} : {}),
        }));
    }
    function signatureModel(signature, seen = new Set()) {
        const node = signature.getDeclaration();
        return {
            parameters: signature.getParameters().map(parameter => {
                const declaration = parameter.valueDeclaration || parameter.declarations?.[0] || node;
                return {name: parameter.name, type: typeModel(checker.getTypeOfSymbolAtLocation(parameter, declaration), declaration?.type || declaration, seen),
                    optional: !!(parameter.flags & ts.SymbolFlags.Optional) || !!declaration?.questionToken || !!declaration?.initializer,
                    rest: !!declaration?.dotDotDotToken,
                };
            }),
            returnType: typeModel(checker.getReturnTypeOfSignature(signature), node?.type || node, seen),
            typeParameters: typeParameters(node?.typeParameters),
            ...(node ? {source: source(node)} : {}),
        };
    }
    function membersOf(symbol, declaration, name) {
        const members = new Map();
        function collect(type, isStatic) {
            for (const property of checker.getPropertiesOfType(type)) {
                const nodes = (property.getDeclarations() || []).filter(node => node.name && isPublic(node) && (
                    ts.isMethodDeclaration(node) || ts.isMethodSignature(node) || ts.isPropertyDeclaration(node)
                    || ts.isPropertySignature(node) || ts.isGetAccessorDeclaration(node) || ts.isSetAccessorDeclaration(node)));
                if (!nodes.length) continue;
                const node = nodes[0];
                const propertyType = checker.getTypeOfSymbolAtLocation(property, declaration);
                const method = ts.isMethodDeclaration(node) || ts.isMethodSignature(node);
                const id = `${name}${isStatic ? '::' : '.'}${memberName(node)}`;
                const member = {id, owner: name, name: memberName(node), kind: method ? 'method' : 'property', static: isStatic,
                    optional: !!(property.flags & ts.SymbolFlags.Optional), source: source(node)};
                if (method) member.signatures = checker.getSignaturesOfType(propertyType, ts.SignatureKind.Call).map(item => signatureModel(item));
                else {
                    member.type = typeModel(propertyType, node.type || node);
                    member.readonly = !!(ts.getCombinedModifierFlags(node) & ts.ModifierFlags.Readonly);
                    if (nodes.some(ts.isGetAccessorDeclaration) || nodes.some(ts.isSetAccessorDeclaration)) {
                        member.accessor = {get: nodes.some(ts.isGetAccessorDeclaration), set: nodes.some(ts.isSetAccessorDeclaration)};
                        member.readonly = !member.accessor.set;
                    }
                }
                members.set(id, member);
            }
        }
        collect(checker.getDeclaredTypeOfSymbol(symbol), false);
        if (ts.isClassDeclaration(declaration)) {
            collect(checker.getTypeOfSymbolAtLocation(symbol, declaration), true);
            const constructors = declaration.members.filter(ts.isConstructorDeclaration);
            const isAbstract = !!(ts.getCombinedModifierFlags(declaration) & ts.ModifierFlags.Abstract);
            let inheritedConstructors = constructors;
            let base = checker.getDeclaredTypeOfSymbol(symbol);
            while (!inheritedConstructors.length && base) {
                base = checker.getBaseTypes(base)?.[0];
                const baseDeclaration = base?.symbol?.declarations?.find(ts.isClassDeclaration);
                inheritedConstructors = baseDeclaration?.members.filter(ts.isConstructorDeclaration) || [];
            }
            const callable = inheritedConstructors.every(isPublic) && (!isAbstract || constructors.length);
            const signatures = callable ? checker.getSignaturesOfType(checker.getTypeOfSymbolAtLocation(symbol, declaration), ts.SignatureKind.Construct) : [];
            if (signatures.length) {
                const id = `${name}.constructor`;
                members.set(id, {id, owner: name, name: 'constructor', kind: 'constructor', static: false,
                    source: source(constructors[0] || declaration), signatures: signatures.map(item => signatureModel(item))});
            }
        }
        return [...members.values()].sort((a, b) => compare(a.id, b.id));
    }
    const classes = [];
    const exports = [];
    const declarations = [];
    for (const {exported, symbol} of publicExports) {
        const nodes = symbol.getDeclarations() || [];
        if (!nodes.length) throw new Error(`Unresolved export: ${exported.name}`);
        if (nodes.every(node => !isPublic(node))) continue;
        const declaration = nodes.find(ts.isClassDeclaration);
        const name = exported.name;
        if (declaration) {
            classes.push({name, qualifiedName: name, kind: 'class', source: source(declaration),
                typeParameters: typeParameters(declaration.typeParameters), heritage: (declaration.heritageClauses || []).flatMap(clause => clause.types.map(node => typeModel(checker.getTypeAtLocation(node), node))),
                members: membersOf(symbol, declaration, name)});
        } else {
            reference(symbol);
            if (symbol.flags & ts.SymbolFlags.Value) {
                exports.push(name);
                const node = nodes[0];
                const type = checker.getTypeOfSymbolAtLocation(symbol, node);
                declarations.push({name, qualifiedName: name, kind: nodes.some(ts.isEnumDeclaration) ? 'enum' : checker.getSignaturesOfType(type, ts.SignatureKind.Call).length ? 'function' : 'variable', source: source(node),
                    type: typeModel(type, node), signatures: checker.getSignaturesOfType(type, ts.SignatureKind.Call).map(item => signatureModel(item))});
            }
        }
    }
    const types = [];
    const processed = new Set();
    for (const [symbol, name] of queuedTypes) {
        if (processed.has(symbol)) continue;
        processed.add(symbol);
        const node = symbol.declarations.find(item => ts.isInterfaceDeclaration(item) || ts.isTypeAliasDeclaration(item) || ts.isEnumDeclaration(item) || ts.isClassDeclaration(item));
        if (ts.isClassDeclaration(node)) continue;
        const type = {name, qualifiedName: name, kind: ts.isInterfaceDeclaration(node) ? 'interface' : ts.isEnumDeclaration(node) ? 'enum' : 'alias', source: source(node),
            declaration: node.getText(), typeParameters: typeParameters(node.typeParameters), refs: refsIn(node)};
        if (ts.isInterfaceDeclaration(node)) {
            const declared = checker.getDeclaredTypeOfSymbol(symbol);
            type.members = membersOf(symbol, node, name);
            type.signatures = checker.getSignaturesOfType(declared, ts.SignatureKind.Call).map(item => signatureModel(item));
            type.constructSignatures = checker.getSignaturesOfType(declared, ts.SignatureKind.Construct).map(item => signatureModel(item));
            type.indexSignatures = checker.getIndexInfosOfType(declared).map(index => ({keyType: typeModel(index.keyType), type: typeModel(index.type), readonly: index.isReadonly}));
            type.heritage = (node.heritageClauses || []).flatMap(clause => clause.types.map(item => typeModel(checker.getTypeAtLocation(item), item)));
        }
        else if (ts.isTypeAliasDeclaration(node)) type.type = typeModel(checker.getTypeAtLocation(node.type), node.type);
        else type.values = node.members.map(member => ({name: memberName(member), value: checker.getConstantValue(member) ?? null}));
        types.push(type);
    }
    // Resolution failures matter; unrelated library diagnostics are intentionally not compiler readiness checks.
    for (const diagnostic of program.getSemanticDiagnostics()) {
        if ([2307, 2304, 2694, 7016].includes(diagnostic.code)) diagnostics.push({code: `typescript-${diagnostic.code}`, message: ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'),
            ...(diagnostic.file ? {path: relative(diagnostic.file.fileName)} : {})});
    }
    return {schemaVersion: 1, classes: classes.sort((a, b) => compare(a.name, b.name)), exports: exports.sort(compare),
        declarations: declarations.sort((a, b) => compare(a.name, b.name)), types: types.sort((a, b) => compare(a.name, b.name)), diagnostics};
}

module.exports = {extractPublicApi};
