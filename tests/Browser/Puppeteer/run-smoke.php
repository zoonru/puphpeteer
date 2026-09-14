<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $output = sys_get_temp_dir() . '/puphpeteer-examples-' . bin2hex(random_bytes(8));
    try {
        if (!mkdir($output, 0700)) { throw new RuntimeException('Cannot create example output directory'); }
        $root = dirname(__DIR__, 3);
        $scripts = [__DIR__ . '/smoke.php', __DIR__ . '/plugins.php', __DIR__ . '/runtime-lifecycle.php', __DIR__ . '/failure-boundaries.php', __DIR__ . '/streams.php', __DIR__ . '/large-payload.php', ...array_map(static fn(string $name): string => "$root/examples/$name.php", ['01_page_open', '02_page_screenshot', '04_form_intercept'])];
        foreach ($scripts as $script) {
            $child = Process::start([PHP_BINARY, $script], $output);
            $result = ProcessRunner::collect($child, 40, stream: true);
            if ($result['code'] !== 0) { throw new RuntimeException(basename($script) . ' failed (' . $result['code'] . ')'); }
        }
    } finally {
        if (is_dir($output)) { ProcessRunner::removeDirectory($output); }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
