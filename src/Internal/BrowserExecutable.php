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

        $source = file_get_contents($packageRoot . '/resources/manifest.json');
        if ($source === false) { throw new \RuntimeException('Missing Puppeteer bundle manifest'); }
        /** @var array{puppeteer:string,chrome:string} $expected */
        $expected = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $directory = $packageRoot;
        do {
            $cache = $directory . '/node_modules/.puphpeteer';
            $manifest = $cache . '/chrome.json';
            if (is_file($manifest)) {
                $contents = file_get_contents($manifest);
                $installed = $contents === false ? null : json_decode($contents, true);
                if (is_array($installed)
                    && ($installed['puppeteer'] ?? null) === $expected['puppeteer']
                    && ($installed['buildId'] ?? null) === $expected['chrome']
                    && is_string($installed['executable'] ?? null)
                ) {
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
