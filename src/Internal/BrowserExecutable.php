<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

/** @internal Resolves a managed installation without invoking Node at runtime. */
final class BrowserExecutable
{
    public static function resolve(string $packageRoot): string
    {
        $configured = getenv('PUPPETEER_EXECUTABLE_PATH') ?: getenv('CHROME_BIN');
        if ($configured !== false && $configured !== '') { return $configured; }

        $directory = $packageRoot;
        do {
            $cache = $directory . '/node_modules/.puphpeteer';
            $metadata = $cache . '/chrome.json';
            if (is_file($metadata)) {
                $contents = file_get_contents($metadata);
                $installed = $contents === false ? null : json_decode($contents, true);
                if (is_array($installed) && is_string($installed['executable'] ?? null)) {
                    $executable = realpath($cache . '/' . $installed['executable']);
                    $cachePath = realpath($cache);
                    if ($executable !== false && $cachePath !== false
                        && str_starts_with($executable, $cachePath . DIRECTORY_SEPARATOR)
                        && is_file($executable) && is_executable($executable)
                    ) { return $executable; }
                }
            }
            $parent = dirname($directory);
            if ($parent === $directory) { break; }
            $directory = $parent;
        } while (true);

        throw new \RuntimeException('Compatible Chrome not found in node_modules; run npm run browser:install in the package directory, or set PUPPETEER_EXECUTABLE_PATH');
    }
}
