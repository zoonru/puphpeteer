'use strict';

const path = require('node:path');
const ts = require('typescript');

function githubLink(lock, source) {
    const repository = lock?.tests?.repository?.replace(/\.git$/, '');
    const revision = lock?.tests?.revision;
    if (!/^https:\/\/github\.com\/[^/]+\/[^/]+$/.test(repository || '') || !/^[a-f0-9]{40}$/.test(revision || '') || !source) return null;
    if (path.isAbsolute(source.path) || source.path.split('/').includes('..')) return null;
    return `${repository}/blob/${revision}/${source.path.split('/').map(encodeURIComponent).join('/')}#L${source.line}`;
}

/** Locate public declarations in the pinned repository, not the bundled npm .d.ts. */
function apiSourceLinks(source, selection) {
    const entry = path.join(source.repositoryRoot, 'packages/puppeteer-core/src/puppeteer-core.ts');
    const program = ts.createProgram([entry], {target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.NodeNext,
        moduleResolution: ts.ModuleResolutionKind.NodeNext, skipLibCheck: true, noEmit: true});
    const checker = program.getTypeChecker();
    const file = program.getSourceFile(entry);
    const module = file && checker.getSymbolAtLocation(file);
    if (!module) throw new Error(`Cannot locate upstream public declarations: ${entry}`);
    const exports = new Map(checker.getExportsOfModule(module).map(symbol => [symbol.name,
        symbol.flags & ts.SymbolFlags.Alias ? checker.getAliasedSymbol(symbol) : symbol]));
    const links = new Map();
    function add(id, node) {
        if (!node) return;
        const file = node.getSourceFile();
        const url = githubLink(source.lock, {path: path.relative(source.repositoryRoot, file.fileName).split(path.sep).join('/'),
            line: file.getLineAndCharacterOfPosition(node.getStart(file)).line + 1});
        if (url) links.set(id, url);
    }
    for (const item of selection.activeClasses) {
        const symbol = exports.get(item.name);
        const declaration = symbol?.declarations?.find(node => ts.isClassDeclaration(node) || ts.isInterfaceDeclaration(node));
        if (!symbol || !declaration) continue;
        add(`class:${item.name}`, declaration);
        for (const member of item.members) {
            if (member.kind === 'constructor') add(member.id, declaration.members.find(ts.isConstructorDeclaration) || declaration);
            else {
                const type = member.static ? checker.getTypeOfSymbolAtLocation(symbol, declaration) : checker.getDeclaredTypeOfSymbol(symbol);
                const property = checker.getPropertyOfType(type, member.name);
                add(member.id, property?.valueDeclaration || property?.declarations?.[0]);
            }
        }
    }
    return links;
}
module.exports = {githubLink, apiSourceLinks};
