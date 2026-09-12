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

        $cacheDirectories = [];
        $directory = $packageRoot;
        do {
            $cacheDirectories[] = $directory . '/node_modules/.puphpeteer';
            $parent = dirname($directory);
            if ($parent === $directory) { break; }
            $directory = $parent;
        } while (true);

        foreach (array_unique($cacheDirectories) as $cache) {
            if (($executable = self::findInstalledChrome($cache)) !== null) { return $executable; }
        }

        throw new \RuntimeException('Compatible Chrome not found in node_modules; run npm run browser:install in the package directory, or set PUPPETEER_EXECUTABLE_PATH');
    }

    private static function findInstalledChrome(string $cache): ?string
    {
        $chromeRoot = realpath($cache . DIRECTORY_SEPARATOR . 'chrome');
        if ($chromeRoot === false || !is_dir($chromeRoot)) { return null; }

        return self::findChromeInDirectory($chromeRoot);
    }

    private static function findChromeInDirectory(string $root): ?string
    {
        $paths = [];
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file->isFile() || !$file->isExecutable()) { continue; }
                if (!in_array($file->getFilename(), ['chrome', 'chrome.exe', 'Google Chrome for Testing'], true)) { continue; }
                $path = $file->getRealPath();
                if ($path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) { $paths[] = $path; }
            }
        } catch (\UnexpectedValueException) {
            return null;
        }

        usort($paths, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));
        return $paths[0] ?? null;
    }

}
