<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Browser\BrowserRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $runner = new BrowserRunner();
    $browser = null;
    $output = sys_get_temp_dir() . '/puphpeteer-examples-' . bin2hex(random_bytes(8));
    try {
        $browser = $runner->launch();
        if (!mkdir($output, 0700)) { throw new RuntimeException('Cannot create example output directory'); }
        $root = dirname(__DIR__, 2);
        foreach ([__DIR__ . '/smoke.php', __DIR__ . '/plugins.php', __DIR__ . '/runtime-lifecycle.php', ...array_map(static fn(string $name): string => "$root/examples/$name.php", ['01_page_open', '02_page_screenshot', '03_browserless'])] as $script) {
            $child = Process::start($runner->command($script), $output, $runner->environment($browser));
            $result = BrowserRunner::collect($child, 40, stream: true);
            if ($result['code'] !== 0) { throw new RuntimeException(basename($script) . ' failed (' . $result['code'] . ')'); }
        }
    } finally {
        try { $browser?->close(); }
        finally {
            $runner->close();
            if (is_dir($output)) { BrowserRunner::removeDirectory($output); }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
