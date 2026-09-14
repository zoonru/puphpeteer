<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

try {
    $root = dirname(__DIR__, 3);
    $options = getopt('', ['cycles:', 'timeout:']);
    $cycles = filter_var($options['cycles'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
    $timeout = filter_var($options['timeout'] ?? '600', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (false === $cycles || false === $timeout) {
        throw new InvalidArgumentException('--cycles must be >= 2 and --timeout must be positive');
    }
    $php = PHP_BINARY;
    echo "Release gate: functional and static checks\n";
    $result = ProcessRunner::collect(Process::start(['composer', 'test', '--no-interaction'], $root), 600, stream: true);
    if (0 !== $result['code']) {
        throw new RuntimeException('Functional and static checks failed (' . $result['code'] . ')');
    }
    echo "Release gate: repeated browser workload\n";
    $result = ProcessRunner::collect(Process::start([$php, __DIR__ . '/workload.php', '--cycles=' . $cycles], $root), $timeout, stream: true);
    if (0 !== $result['code']) {
        throw new RuntimeException('Repeated workload failed (' . $result['code'] . ')');
    }
    echo "Release gate PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
