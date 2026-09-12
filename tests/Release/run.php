<?php

declare(strict_types=1);

use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\ProcessRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $root = dirname(__DIR__, 2);
    $extension = getenv('QUICKJS_EXTENSION');
    if (!$extension) { throw new RuntimeException('Set QUICKJS_EXTENSION. See docs/quickjs.md.'); }
    $php = getenv('PHP_BIN') ?: PHP_BINARY;
    $steps = [
        ['unit and generation', [$php, 'vendor/bin/phpunit'], 180],
        ['extension contract', [$php, '-n', '-d', 'extension=' . $extension, $root . '/vendor/bin/phpunit', 'tests/Integration'], 180],
        ['browser compatibility and examples', [$php, 'tests/Browser/run-smoke.php'], 180],
    ];
    foreach ($steps as [$name, $command, $timeout]) {
        echo "Release gate: $name\n";
        $result = ProcessRunner::collect(Process::start($command, $root), $timeout, stream: true);
        if ($result['code'] !== 0) { throw new RuntimeException("$name failed ({$result['code']})"); }
    }
    echo "Release gate: repeated browser workload\n";
    $timeout = filter_var(getenv('RELEASE_TIMEOUT') === false ? '600' : getenv('RELEASE_TIMEOUT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($timeout === false) { throw new InvalidArgumentException('RELEASE_TIMEOUT must be a positive integer'); }
    $result = ProcessRunner::collect(Process::start([$php, '-n', '-d', 'extension=' . $extension, __DIR__ . '/workload.php'], $root), $timeout, stream: true);
    if ($result['code'] !== 0) { throw new RuntimeException('Repeated workload failed (' . $result['code'] . ')'); }
    echo "Release gate PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
