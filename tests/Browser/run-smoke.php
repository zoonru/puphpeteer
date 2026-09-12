<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\ProcessRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $extension = getenv('QUICKJS_EXTENSION');
    if (!$extension) { throw new RuntimeException('Set QUICKJS_EXTENSION. See docs/quickjs.md.'); }
    $output = sys_get_temp_dir() . '/puphpeteer-examples-' . bin2hex(random_bytes(8));
    try {
        if (!mkdir($output, 0700)) { throw new RuntimeException('Cannot create example output directory'); }
        $root = dirname(__DIR__, 2);
        $fixture = 'file://' . $root . '/examples/pages/index.html';
        $environment = [...getenv(), 'EXAMPLE_URL' => $fixture];
        $php = getenv('PHP_BIN') ?: PHP_BINARY;
        $scripts = [__DIR__ . '/smoke.php', __DIR__ . '/plugins.php', __DIR__ . '/runtime-lifecycle.php', ...array_map(static fn(string $name): string => "$root/examples/$name.php", ['01_page_open', '02_page_screenshot', '04_form_intercept'])];
        foreach ($scripts as $script) {
            $child = Process::start([$php, '-n', '-d', 'extension=' . $extension, $script], $output, $environment);
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
