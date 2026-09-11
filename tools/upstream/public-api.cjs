'use strict';

const ts = require('typescript');

function isPublic(node) {
    const flags = ts.getCombinedModifierFlags(node);
    return !(flags & (ts.ModifierFlags.Private | ts.ModifierFlags.Protected))
        && !(node.name && ts.isPrivateIdentifier(node.name))
        && !ts.getJSDocTags(node).some(tag => tag.tagName.text === 'internal');
}

function memberName(node) {
    if (ts.isConstructorDeclaration(node)) return 'constructor';
    if (ts.isComputedPropertyName(node.name)) return node.name.getText();
    return node.name.text;
}

/** Inventory the package's public declarations, never its implementation files. */
function extractPublicApi(entry) {
    const program = ts.createProgram([entry], {
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.NodeNext,
        moduleResolution: ts.ModuleResolutionKind.NodeNext,
        skipLibCheck: true,
        noEmit: true,
    });
    const diagnostics = program.getSyntacticDiagnostics();
    if (diagnostics.length) {
        throw new Error(ts.formatDiagnosticsWithColorAndContext(diagnostics, {
            getCanonicalFileName: file => file,
            getCurrentDirectory: () => process.cwd(),
            getNewLine: () => '\n',
        }));
    }
    const checker = program.getTypeChecker();
    const source = program.getSourceFile(entry);
    const moduleSymbol = source && checker.getSymbolAtLocation(source);
    if (!moduleSymbol) throw new Error(`Cannot resolve public module: ${entry}`);
    const classes = [];
    const exports = [];
    for (const exported of checker.getExportsOfModule(moduleSymbol)) {
        const symbol = exported.flags & ts.SymbolFlags.Alias
            ? checker.getAliasedSymbol(exported) : exported;
        const declarations = symbol.getDeclarations() || [];
        if (!declarations.length) throw new Error(`Unresolved export: ${exported.name}`);
        if (declarations.every(node => !isPublic(node))) continue;
        const declaration = declarations.find(ts.isClassDeclaration);
        if (!declaration) {
            // Interfaces and aliases are signature types, not independent runtime API.
            if (symbol.flags & ts.SymbolFlags.Value) exports.push(exported.name);
            continue;
        }
        const name = exported.name;
        const members = new Map();
        function collect(type, isStatic) {
            for (const property of checker.getPropertiesOfType(type)) {
                const nodes = (property.getDeclarations() || []).filter(node =>
                    node.name && isPublic(node) && (
                        ts.isMethodDeclaration(node) || ts.isMethodSignature(node)
                        || ts.isPropertyDeclaration(node) || ts.isPropertySignature(node)
                        || ts.isGetAccessorDeclaration(node) || ts.isSetAccessorDeclaration(node)
                    ));
                if (!nodes.length) continue;
                const node = nodes[0];
                const id = `${name}${isStatic ? '::' : '.'}${memberName(node)}`;
                members.set(id, {
                    id,
                    kind: ts.isMethodDeclaration(node) || ts.isMethodSignature(node)
                        ? 'method' : 'property',
                });
            }
        }
        // Include inherited public members under the visible receiver class.
        collect(checker.getDeclaredTypeOfSymbol(symbol), false);
        collect(checker.getTypeOfSymbolAtLocation(symbol, declaration), true);
        if (declaration.members.some(node => ts.isConstructorDeclaration(node) && isPublic(node))) {
            const id = `${name}.constructor`;
            members.set(id, {id, kind: 'constructor'});
        }
        classes.push({name, members: [...members.values()].sort((a, b) => compare(a.id, b.id))});
    }
    return {classes: classes.sort((a, b) => compare(a.name, b.name)), exports: exports.sort(compare)};
}

function compare(a, b) { return a < b ? -1 : a > b ? 1 : 0; }

module.exports = {extractPublicApi};
