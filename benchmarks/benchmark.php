<?php

declare(strict_types=1);
use Nesk\Puphpeteer\Puppeteer;

require dirname(__DIR__) . '/vendor/autoload.php';
use function Amp\async;
use function Amp\Future\await;

$backend = 'quickjs';
$count = filter_var(getopt('', ['iterations:'])['iterations'] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (false === $count) {
    throw new InvalidArgumentException('--iterations must be a positive integer');
}
$cpu = static function (): float {
    $r = getrusage();

    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
};
$started = hrtime(true);
$puppeteer = new Puppeteer();
$browser = $puppeteer->connect(['browserWSEndpoint' => getenv('BROWSER_WS')]);
$fixture = 'file://' . dirname(__DIR__) . '/examples/pages/index.html';
$context = $browser->createBrowserContext();
$page = $context->newPage();
$page->goto($fixture);
$page->bringToFront();
if ('PuPHPeteer example' !== $page->title()) {
    throw new RuntimeException('Wrong fixture');
}
$setupMs = (hrtime(true) - $started) / 1e6;
$phases = [];
$measure = function (string $name, int $operations, callable $fn) use (&$phases, $cpu): void {
    $start = hrtime(true);
    $cpuStart = $cpu();
    $fn();
    $phases[$name] = ['operations' => $operations, 'wall_ms' => (hrtime(true) - $start) / 1e6, 'php_cpu_ms' => ($cpu() - $cpuStart) * 1000];
};
try {
    for ($i = 0; $i < 50; ++$i) {
        if (42 !== $page->evaluate('21 * 2')) {
            throw new RuntimeException('Warmup mismatch');
        }
    }
    echo "BENCH_READY\n";
    flush();
    $latencies = [];
    $measure('evaluate', $count, function () use ($page, $count, &$latencies): void {
        for ($i = 0; $i < $count; ++$i) {
            $start = hrtime(true);
            if (42 !== $page->evaluate('21 * 2')) {
                throw new RuntimeException('Evaluate mismatch');
            }
            $latencies[] = (hrtime(true) - $start) / 1e6;
        }
    });
    sort($latencies);
    $phases['evaluate']['p50_ms'] = $latencies[(int) floor((count($latencies) - 1) * .5)];
    $phases['evaluate']['p95_ms'] = $latencies[(int) floor((count($latencies) - 1) * .95)];
    $measure('payload_64k', 100, function () use ($page): void {
        for ($i = 0; $i < 100; ++$i) {
            $value = $page->evaluate('"x".repeat(65536)');
            if (65536 !== strlen($value)) {
                throw new RuntimeException('Payload mismatch');
            }
        }
    });
    $measure('concurrent_20x25ms', 20, function () use ($page): void {
        $tasks = [];
        for ($i = 0; $i < 20; ++$i) {
            $tasks[] = async(fn () => $page->evaluate('new Promise(r => setTimeout(() => r(42), 25))'));
        }
        foreach (await($tasks) as $value) {
            if (42 !== $value) {
                throw new RuntimeException('Concurrent mismatch');
            }
        }
    });
    $measure('navigate_title', 20, function () use ($page, $fixture): void {
        for ($i = 0; $i < 20; ++$i) {
            $page->goto($fixture . '?i=' . $i);
            if ('PuPHPeteer example' !== $page->title()) {
                throw new RuntimeException('Navigation mismatch');
            }
        }
    });
    echo 'BENCH_RESULT ', json_encode(['backend' => $backend, 'php' => PHP_VERSION, 'setup_ms' => $setupMs, 'phases' => $phases, 'php_peak_allocated_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
    flush();
} finally {
    $context->close();
    $browser->disconnect();
}
