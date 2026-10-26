<?php

declare(strict_types=1);

use Amp\DeferredFuture;
use Revolt\EventLoop;

use function Amp\async;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['iterations:', 'trials:', 'scenario:']);
$iterations = filter_var($options['iterations'] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$trials = filter_var($options['trials'] ?? '5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$scenario = $options['scenario'] ?? 'all';
if (false === $iterations || false === $trials || !in_array($scenario, ['all', 'eval', 'map', 'overlap'], true)) {
    throw new InvalidArgumentException('Use positive --iterations/--trials and --scenario=all|eval|map|overlap');
}
$cpu = static function (): float {
    $usage = getrusage();

    return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000
        + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
};
$measure = static function (string $name, callable $operation) use ($cpu): void {
    $start = hrtime(true);
    $cpuStart = $cpu();
    $result = $operation();
    echo json_encode(['scenario' => $name, 'wall_ms' => (hrtime(true) - $start) / 1e6,
        'cpu_ms' => $cpu() - $cpuStart, 'result' => $result], JSON_THROW_ON_ERROR), "\n";
};
$js = new QuickJS();
$js->register('map', static fn (array $items, callable $fn): array => array_map($fn, $items));
$started = null;
$js->register('started', static function () use (&$started): void { $started?->complete(); });
$items = range(1, 100);
$expected = array_map(static fn (int $n): int => $n * $n, $items);
$js->eval('globalThis.items = Array.from({length: 100}, (_, i) => i + 1)', typescript: false);
// Compile and warm the exact sources before measuring.
$sources = ['eval' => '21 * 2', 'map' => 'php.map(items, n => n * n)', 'js_map' => 'items.map(n => n * n)'];
foreach ($sources as $source) {
    $js->eval($source, typescript: false);
}
// Signal entry from JS before starting PHP's blocking operation.
$work = '(function () { php.started(); const end = Date.now() + 100; while (Date.now() < end) {} return 42; })()';
echo json_encode(['php' => PHP_VERSION, 'uv' => phpversion('uv'), 'event_loop' => get_class(EventLoop::getDriver()),
    'extension' => phpversion('php_quickjs'), 'iterations' => $iterations, 'map_items' => count($items), 'trials' => $trials], JSON_THROW_ON_ERROR), "\n";
for ($trial = 1; $trial <= $trials; ++$trial) {
    echo json_encode(['trial' => $trial], JSON_THROW_ON_ERROR), "\n";
    if (in_array($scenario, ['all', 'eval'], true)) {
        $measure('eval', static function () use ($js, $sources, $iterations): int {
            for ($i = 0; $i < $iterations; ++$i) {
                if (42 !== $js->eval($sources['eval'], typescript: false)) {
                    throw new RuntimeException('eval mismatch');
                }
            }

            return 42;
        });
    }
    if (in_array($scenario, ['all', 'map'], true)) {
        foreach (['php_map', 'js_map', 'map'] as $mode) {
            $measure($mode, static function () use ($js, $sources, $iterations, $items, $expected, $mode): int {
                for ($i = 0; $i < $iterations; ++$i) {
                    $result = 'php_map' === $mode
                        ? array_map(static fn (int $n): int => $n * $n, $items)
                        : $js->eval($sources[$mode], typescript: false);
                    if ($expected !== $result) {
                        throw new RuntimeException('map mismatch');
                    }
                }

                return array_sum($result);
            });
        }
    }
    if (in_array($scenario, ['all', 'overlap'], true)) {
        // Alternate ordering so sequential/concurrent do not always run first.
        foreach (0 === $trial % 2 ? ['overlap', 'sequential'] : ['sequential', 'overlap'] as $mode) {
            $measure($mode, static function () use ($js, $work, &$started, $mode): int {
                if ('sequential' === $mode) {
                    $result = $js->eval($work, typescript: false);
                    usleep(100_000);
                } else {
                    $started = new DeferredFuture();
                    $task = async(static fn (): mixed => $js->eval($work, typescript: false));
                    $started->getFuture()->await();
                    usleep(100_000);
                    $result = $task->await();
                    $started = null;
                }
                if (42 !== $result) {
                    throw new RuntimeException('overlap mismatch');
                }

                return $result;
            });
        }
    }
}
