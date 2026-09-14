<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use RuntimeException;

/** @internal Resolves the same pinned installation as browser:install. */
final class BrowserExecutable
{
    public static function resolve(string $packageRoot): string
    {
        $configured = getenv('PUPPETEER_EXECUTABLE_PATH');
        if (false !== $configured && '' !== $configured) {
            return $configured;
        }
        $installation = new BrowserInstallation($packageRoot);
        $executable = $installation->executable();
        if (is_file($executable) && is_executable($executable)) {
            return $executable;
        }
        throw new RuntimeException('Compatible Chrome not found at ' . $executable . '; run php bin/console browser:install in the package directory, or set PUPPETEER_EXECUTABLE_PATH');
    }
}
