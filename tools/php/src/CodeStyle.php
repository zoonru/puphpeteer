<?php

declare(strict_types=1);

namespace Zoon\Puphpeteer\Tooling;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Applies the project style before generated files are compared or written. */
final class CodeStyle
{
    public static function format(string $content): string
    {
        $root = dirname(__DIR__, 3);
        $temporary = tempnam(sys_get_temp_dir(), 'puphpeteer-style-');
        if (false === $temporary) {
            throw new RuntimeException('Cannot create PHP formatting file');
        }
        try {
            file_put_contents($temporary, $content);
            $process = new Process([
                PHP_BINARY, $root . '/vendor/bin/php-cs-fixer', 'fix', $temporary,
                '--config=' . $root . '/.php-cs-fixer.dist.php', '--path-mode=override',
                '--using-cache=no', '--sequential',
            ]);
            $process->mustRun();
            $formatted = file_get_contents($temporary);
            if (false === $formatted) {
                throw new RuntimeException('Cannot read formatted PHP');
            }

            return $formatted;
        } finally {
            unlink($temporary);
        }
    }
}
