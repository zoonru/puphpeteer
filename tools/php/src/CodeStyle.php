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
        return self::formatMany([$content])[0];
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, string> $sources
     *
     * @return array<TKey, string>
     */
    public static function formatMany(array $sources): array
    {
        if ([] === $sources) {
            return [];
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/puphpeteer-style-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create PHP formatting directory');
        }
        $paths = [];
        try {
            foreach ($sources as $key => $content) {
                $path = $directory . '/' . count($paths) . '.php';
                $paths[$key] = $path;
                if (false === file_put_contents($path, $content)) {
                    throw new RuntimeException('Cannot write PHP formatting file');
                }
            }
            $process = new Process([
                PHP_BINARY, $root . '/vendor/bin/php-cs-fixer', 'fix', $directory,
                '--config=' . $root . '/.php-cs-fixer.dist.php', '--path-mode=override',
                '--using-cache=no', '--sequential',
            ]);
            $process->mustRun();
            foreach ($paths as $key => $path) {
                $formatted = file_get_contents($path);
                if (false === $formatted) {
                    throw new RuntimeException('Cannot read formatted PHP');
                }
                $sources[$key] = $formatted;
            }

            return $sources;
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }
}
