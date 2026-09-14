<?php

declare(strict_types=1);
use Nesk\Puphpeteer\Internal\BrowserInstallation;

require_once __DIR__ . '/../src/Internal/BrowserInstallation.php';

try {
    $path = BrowserInstallation::skipDownload()
        ? null
        : (new BrowserInstallation(dirname(__DIR__)))->install();
    fwrite(STDOUT, ($path ?? 'Chrome download skipped.') . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
