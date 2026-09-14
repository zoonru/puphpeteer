<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Internal/BrowserInstallation.php';

try {
    $path = \Nesk\Puphpeteer\Internal\BrowserInstallation::skipDownload()
        ? null
        : (new \Nesk\Puphpeteer\Internal\BrowserInstallation(dirname(__DIR__)))->install();
    fwrite(STDOUT, ($path ?? 'Chrome download skipped.') . PHP_EOL);
} catch (\Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
