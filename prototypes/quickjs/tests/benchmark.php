<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
use Amp\Future;
use function Amp\async;
use function Amp\Future\await;
$backend = $argv[1] ?? 'quickjs';
$count = (int) (getenv('BENCH_ITERATIONS') ?: 1000);
$resolve = static fn($value) => $value instanceof Future ? $value->await() : $value;
$cpu = static function (): float { $r = getrusage(); return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6; };
$started = hrtime(true);
$client = null;
$browser = match ($backend) {
    'quickjs' => ($client = new PuphpeteerQuickJs\Client())->connect(getenv('BROWSER_WS'))->await(),
    'native' => (new Nesk\Puphpeteer\Native\Puppeteer())->connect(['browserWSEndpoint' => getenv('BROWSER_WS')])->await(),
    'rialto' => (new Nesk\Puphpeteer\Puppeteer())->connect(['browserWSEndpoint' => getenv('BROWSER_WS'), 'defaultViewport' => null]),
    default => throw new InvalidArgumentException('Unknown backend'),
};
$context = $resolve($browser->createBrowserContext());
$page = $resolve($context->newPage());
$resolve($page->goto(getenv('FIXTURE_URL')));
$resolve($page->bringToFront());
if ($resolve($page->title()) !== 'QuickJS fixture') { throw new RuntimeException('Wrong fixture'); }
$setupMs = (hrtime(true) - $started) / 1e6;
$phases = [];
$measure = function (string $name, int $operations, callable $fn) use (&$phases, $cpu): void {
    $start = hrtime(true); $cpuStart = $cpu();
    $fn();
    $phases[$name] = ['operations' => $operations, 'wall_ms' => (hrtime(true) - $start) / 1e6, 'php_cpu_ms' => ($cpu() - $cpuStart) * 1000];
};
try {
    for ($i = 0; $i < 50; $i++) { if ($resolve($page->evaluate('21 * 2')) !== 42) { throw new RuntimeException('Warmup mismatch'); } }
    echo "BENCH_READY\n"; flush();
    $latencies = [];
    $measure('evaluate', $count, function () use ($page, $resolve, $count, &$latencies): void {
        for ($i = 0; $i < $count; $i++) {
            $start = hrtime(true);
            if ($resolve($page->evaluate('21 * 2')) !== 42) { throw new RuntimeException('Evaluate mismatch'); }
            $latencies[] = (hrtime(true) - $start) / 1e6;
        }
    });
    sort($latencies);
    $phases['evaluate']['p50_ms'] = $latencies[(int) floor((count($latencies) - 1) * .5)];
    $phases['evaluate']['p95_ms'] = $latencies[(int) floor((count($latencies) - 1) * .95)];
    $measure('payload_64k', 100, function () use ($page, $resolve): void {
        for ($i = 0; $i < 100; $i++) {
            $value = $resolve($page->evaluate('"x".repeat(65536)'));
            if (strlen($value) !== 65536) { throw new RuntimeException('Payload mismatch'); }
        }
    });
    $measure('concurrent_20x25ms', 20, function () use ($page, $resolve): void {
        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $tasks[] = async(fn() => $resolve($page->evaluate('new Promise(r => setTimeout(() => r(42), 25))')));
        }
        foreach (await($tasks) as $value) { if ($value !== 42) { throw new RuntimeException('Concurrent mismatch'); } }
    });
    $measure('navigate_title', 20, function () use ($page, $resolve): void {
        for ($i = 0; $i < 20; $i++) {
            $resolve($page->goto(getenv('FIXTURE_URL') . '?i=' . $i));
            if ($resolve($page->title()) !== 'QuickJS fixture') { throw new RuntimeException('Navigation mismatch'); }
        }
    });
    echo 'BENCH_RESULT ', json_encode(['backend' => $backend, 'php' => PHP_VERSION, 'setup_ms' => $setupMs, 'phases' => $phases, 'php_peak_allocated_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
    flush();
} finally {
    $resolve($context->close());
    if ($client) { $client->close(); } else { $browser->disconnect(); }
}
