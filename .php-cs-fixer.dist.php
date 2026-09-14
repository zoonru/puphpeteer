<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use Zoon\Puphpeteer\Tooling\GlobalClassImportFixer;

require_once __DIR__ . '/tools/php/src/GlobalClassImportFixer.php';

return (new Config())
    ->registerCustomFixers([new GlobalClassImportFixer()])
    ->setRules([
        '@Symfony' => true,
        'Puphpeteer/global_class_import' => true,
        'global_namespace_import' => false,
        'concat_space' => ['spacing' => 'one'],
        'fully_qualified_strict_types' => ['import_symbols' => true],
    ])
    ->setRiskyAllowed(false)
    ->setFinder((new Finder())
        ->in(__DIR__)
        ->exclude(['vendor', 'node_modules'])
        ->append([__FILE__, __DIR__ . '/bin/console']))
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
