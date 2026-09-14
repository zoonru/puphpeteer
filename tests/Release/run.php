<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\ProcessRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $root = dirname(__DIR__, 2);
    $options = getopt('', ['cycles:', 'timeout:']);
    $cycles = filter_var($options['cycles'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
    $timeout = filter_var($options['timeout'] ?? '600', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cycles === false || $timeout === false) { throw new InvalidArgumentException('--cycles must be >= 2 and --timeout must be positive'); }
    $php = PHP_BINARY;
    $steps = [
        ['unit and generation', [$php, 'vendor/bin/phpunit'], 180],
        ['extension contract', [$php, $root . '/vendor/bin/phpunit', 'tests/Integration'], 180],
        ['browser compatibility and examples', [$php, 'tests/Browser/run-smoke.php'], 180],
    ];
    foreach ($steps as [$name, $command, $stepTimeout]) {
        echo "Release gate: $name\n";
        $result = ProcessRunner::collect(Process::start($command, $root), $stepTimeout, stream: true);
        if ($result['code'] !== 0) { throw new RuntimeException("$name failed ({$result['code']})"); }
    }
    echo "Release gate: repeated browser workload\n";
    $result = ProcessRunner::collect(Process::start([$php, __DIR__ . '/workload.php', '--cycles=' . $cycles], $root), $timeout, stream: true);
    if ($result['code'] !== 0) { throw new RuntimeException('Repeated workload failed (' . $result['code'] . ')'); }
    echo "Release gate PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
