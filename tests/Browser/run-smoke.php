<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $output = sys_get_temp_dir() . '/puphpeteer-examples-' . bin2hex(random_bytes(8));
    try {
        if (!mkdir($output, 0700)) {
            throw new RuntimeException('Cannot create example output directory');
        }
        $root = dirname(__DIR__, 2);
        $scripts = glob(__DIR__ . '/Puppeteer/*.php') ?: [];
        array_push($scripts, ...array_map(static fn (string $name): string => "$root/examples/$name.php", ['01_page_open', '02_page_screenshot', '04_form_intercept', '05_video_stream', '06_stealth_fingerprint']));
        foreach ($scripts as $script) {
            if (__DIR__ . '/Puppeteer/browserless.php' === $script && !getenv('BROWSER_WS')) {
                echo "Browserless checks SKIP: BROWSER_WS is not set\n";

                continue;
            }
            $child = Process::start([PHP_BINARY, $script], $output);
            $result = ProcessRunner::collect($child, 40, stream: true);
            if (0 !== $result['code']) {
                throw new RuntimeException(basename($script) . ' failed (' . $result['code'] . ')');
            }
        }
    } finally {
        if (is_dir($output)) {
            ProcessRunner::removeDirectory($output);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
