<?php

declare(strict_types=1);
use Amp\Future;
use Nesk\Puphpeteer\Native\Puppeteer as NativePuppeteer;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Puppeteer\Puppeteer as QuickJsPuppeteer;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\Future\await;

$options = getopt('', ['iterations:', 'backend:', 'reference:']);
$backend = $options['backend'] ?? 'quickjs';
$reference = $options['reference'] ?? getenv('PUPPETEER_BENCHMARK_REFERENCE') ?: dirname(__DIR__) . '/.cache/benchmarks/reference';
if (!in_array($backend, ['quickjs', 'quickjs-baseline', 'native', 'rialto'], true)) {
    throw new InvalidArgumentException('Unknown backend');
}
if ('quickjs-baseline' === $backend) {
    require $reference . '/quickjs/vendor/autoload.php';
} elseif ('quickjs' === $backend) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} else {
    require $reference . '/rialto/vendor/autoload.php';
    if ('native' === $backend) {
        spl_autoload_register(static function (string $class) use ($reference): void {
            $prefix = 'Nesk\\Puphpeteer\\Native\\';
            if (str_starts_with($class, $prefix)) {
                $file = $reference . '/native/src/Native/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        }, true, true);
    }
}
$resolve = static fn (mixed $value): mixed => $value instanceof Future ? $value->await() : $value;
$count = filter_var($options['iterations'] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (false === $count) {
    throw new InvalidArgumentException('--iterations must be a positive integer');
}
$cpu = static function (): float {
    $r = getrusage();

    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
};
$started = hrtime(true);
$puppeteer = match ($backend) {
    'quickjs', 'quickjs-baseline' => new QuickJsPuppeteer(),
    'native' => new NativePuppeteer(),
    'rialto' => new Puppeteer(),
};
$browser = $resolve($puppeteer->connect(['browserWSEndpoint' => getenv('BROWSER_WS')]));
$fixture = 'file://' . dirname(__DIR__) . '/examples/pages/index.html';
$context = $resolve($browser->createBrowserContext());
$page = $resolve($context->newPage());
$resolve($page->goto($fixture));
if ('PuPHPeteer example' !== $resolve($page->title())) {
    throw new RuntimeException('Wrong fixture');
}
$setupMs = (hrtime(true) - $started) / 1e6;
$phases = [];
$measure = function (string $name, int $operations, callable $fn) use (&$phases, $cpu): void {
    $lastTick = hrtime(true);
    $maxLag = 0.0;
    $heartbeat = EventLoop::repeat(.001, static function () use (&$lastTick, &$maxLag): void {
        $now = hrtime(true);
        $maxLag = max($maxLag, ($now - $lastTick) / 1e6 - 1);
        $lastTick = $now;
    });
    $start = hrtime(true);
    $cpuStart = $cpu();
    try {
        $fn();
        $end = hrtime(true);
        // Include a final synchronous stall even if the timer never got to run.
        $maxLag = max($maxLag, ($end - $lastTick) / 1e6 - 1);
        $phases[$name] = ['operations' => $operations, 'wall_ms' => ($end - $start) / 1e6, 'php_cpu_ms' => ($cpu() - $cpuStart) * 1000, 'max_loop_lag_ms' => $maxLag];
    } finally {
        EventLoop::cancel($heartbeat);
    }
};
try {
    for ($i = 0; $i < 50; ++$i) {
        if (42 !== $resolve($page->evaluate('21 * 2'))) {
            throw new RuntimeException('Warmup mismatch');
        }
    }
    echo "BENCH_READY\n";
    flush();
    $latencies = [];
    $measure('evaluate', $count, function () use ($page, $count, $resolve, &$latencies): void {
        for ($i = 0; $i < $count; ++$i) {
            $start = hrtime(true);
            if (42 !== $resolve($page->evaluate('21 * 2'))) {
                throw new RuntimeException('Evaluate mismatch');
            }
            $latencies[] = (hrtime(true) - $start) / 1e6;
        }
    });
    sort($latencies);
    $phases['evaluate']['p50_ms'] = $latencies[(int) floor((count($latencies) - 1) * .5)];
    $phases['evaluate']['p95_ms'] = $latencies[(int) floor((count($latencies) - 1) * .95)];
    $measure('payload_64k', 100, function () use ($page, $resolve): void {
        for ($i = 0; $i < 100; ++$i) {
            $value = $resolve($page->evaluate('"x".repeat(65536)'));
            if (65536 !== strlen($value)) {
                throw new RuntimeException('Payload mismatch');
            }
        }
    });
    $measure('concurrent_20x25ms', 20, function () use ($page, $resolve): void {
        $tasks = [];
        for ($i = 0; $i < 20; ++$i) {
            $tasks[] = async(fn () => $resolve($page->evaluate('new Promise(r => setTimeout(() => r(42), 25))')));
        }
        foreach (await($tasks) as $value) {
            if (42 !== $value) {
                throw new RuntimeException('Concurrent mismatch');
            }
        }
    });
    $measure('navigate_title', 20, function () use ($page, $fixture, $resolve): void {
        for ($i = 0; $i < 20; ++$i) {
            $resolve($page->goto($fixture . '?i=' . $i));
            if ('PuPHPeteer example' !== $resolve($page->title())) {
                throw new RuntimeException('Navigation mismatch');
            }
        }
    });
    echo 'BENCH_RESULT ', json_encode(['backend' => $backend, 'extension_version' => phpversion('php_quickjs'), 'php' => PHP_VERSION, 'uv' => phpversion('uv'), 'event_loop' => get_class(EventLoop::getDriver()), 'os' => PHP_OS_FAMILY, 'arch' => php_uname('m'), 'setup_ms' => $setupMs, 'phases' => $phases, 'php_peak_allocated_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
    flush();
} finally {
    $resolve($context->close());
    $resolve($browser->disconnect());
}
