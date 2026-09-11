'use strict';

/** Literal aliases are generated so both PHP and IDEs can discover old names. */
function generateAliases(classes) {
    const aliases = classes.map(({name, fqcn}) => ({target: fqcn, alias: `Nesk\\Puphpeteer\\Resources\\${name}`}));
    aliases.push({target: 'Nesk\\Puphpeteer\\JsFunction', alias: 'Nesk\\Rialto\\Data\\JsFunction'});
    const blocks = aliases.map(({target, alias}) => {
        for (const name of [target, alias]) {
            if (!/^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*$/.test(name)) throw new Error(`Invalid alias class: ${name}`);
        }
        return `if (!class_exists(\\${alias}::class)) {\n    class_alias(\\${target}::class, \\${alias}::class);\n}`;
    });
    return {path: 'src/compatibility.php', content: `<?php\n\n// @generated aliases by tools/upstream/update.cjs. Do not edit.\n\ndeclare(strict_types=1);\n\n${blocks.join('\n\n')}\n`};
}
module.exports = {generateAliases};
