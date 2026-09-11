<?php

declare(strict_types=1);

use Zoon\Puphpeteer\Tooling\Request;
use Zoon\Puphpeteer\Tooling\Synchronizer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $input = file_get_contents('php://stdin');
    if ($input === false) {
        throw new RuntimeException('Cannot read synchronization request');
    }
    $request = Request::decode($input);
    $result = (new Synchronizer())->synchronize($request['root'], $request['classes']);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($result['diagnostics'] === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
