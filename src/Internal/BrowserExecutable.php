<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use RuntimeException;

use function Amp\File\getStatus;

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
        $status = getStatus($executable);
        if (null !== $status && 0100000 === ($status['mode'] & 0170000) && (PHP_OS_FAMILY === 'Windows' || 0 !== ($status['mode'] & 0111))) {
            return $executable;
        }
        throw new RuntimeException('Compatible Chrome not found at ' . $executable . '; run php bin/console browser:install in the package directory, or set PUPPETEER_EXECUTABLE_PATH');
    }
}
