<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
ini_set('display_errors', 'stderr');

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Tests\Support\LargePayload;
use Revolt\EventLoop;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use function Amp\delay;

$options = getopt('', ['sizes:', 'trials:', 'mode:', 'json']);
$sizes = explode(',', $options['sizes'] ?? '4,8,12');
$trials = filter_var($options['trials'] ?? 3, FILTER_VALIDATE_INT);
$mode = $options['mode'] ?? 'all';
if (!$trials || $trials < 1 || !in_array($mode, ['all', 'bridge', 'browser', 'stream'], true)) {
    throw new InvalidArgumentException('Use --trials=N --mode=all|bridge|browser|stream --sizes=4,8,12 [--json]');
}
foreach ($sizes as &$size) {
    if (!ctype_digit($size) || (int) $size < 1 || (int) $size > ($mode === 'stream' ? 32 : 12)) {
        throw new InvalidArgumentException('Sizes are MiB: 1..12 for single values; 1..32 for --mode=stream');
    }
    $size = (int) $size * 1024 * 1024;
}
unset($size);
function cpuMs(): float {
    $r = getrusage();
    return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
}
function measure(callable $run, array $expected): array {
    $last = hrtime(true); $gap = 0.0;
    $heartbeat = EventLoop::repeat(0.001, static function () use (&$last, &$gap): void {
        $now = hrtime(true); $gap = max($gap, ($now - $last) / 1e6 - 1); $last = $now;
    });
    try {
        delay(0.002);
        $last = hrtime(true); $gap = 0.0;
        $cpu = cpuMs(); $start = hrtime(true);
        $digest = LargePayload::digest($run());
        $elapsed = (hrtime(true) - $start) / 1e6; $cpu = cpuMs() - $cpu;
        // Observe a synchronous stall even if the operation never yielded.
        $gap = max($gap, (hrtime(true) - $last) / 1e6 - 1);
        if ($digest['bytes'] !== $expected['bytes'] || $digest['sha256'] !== $expected['sha256']) {
            throw new RuntimeException('Payload length or SHA-256 mismatch');
        }
        return ['ms' => $elapsed, 'cpu_ms' => $cpu, 'mib_s' => $digest['bytes'] / 1048576 / ($elapsed / 1000), 'max_gap_ms' => max(0, $gap), 'chunks' => $digest['chunks']];
    } finally { EventLoop::cancel($heartbeat); }
}
$client = $mode !== 'browser' ? LargePayload::client() : null;
$browser = in_array($mode, ['all', 'browser'], true) ? (new Puppeteer())->launch() : null;
$runs = []; $summary = [];
$chromeVersion = $browser?->version();
try {
    $page = $browser?->newPage();
    foreach ($sizes as $size) {
        $client?->call(-1, 'prepare', [$size])->await();
        if ($page) { LargePayload::preparePage($page, $size); }
        $text = LargePayload::expected($size);
        $expectedText = LargePayload::digest($text);
        $expectedHtml = LargePayload::digest(LargePayload::HTML_START . $text . LargePayload::HTML_END);
        $binary = LargePayload::expected($size, true);
        $expectedBinary = LargePayload::digest($binary);
        unset($text);
        $cases = ['PHP hash baseline' => [fn() => $binary, $expectedBinary]];
        if ($client) {
            if ($mode !== 'stream') {
                $cases['bridge function'] = [fn() => $client->call(-1, 'invoke', [new JsFunction('(text) => text')])->await(), $expectedText];
                $cases['bridge binary'] = [fn() => $client->call(-1, 'bytes', [])->await(), $expectedBinary];
            }
            $cases['bridge stream'] = [fn() => $client->call(-1, 'stream', [$size])->await(), $expectedBinary];
        }
        if ($page) {
            $cases['browser HTML'] = [fn() => $page->content(), $expectedHtml];
            $cases['browser function'] = [fn() => $page->evaluate(new JsFunction('() => globalThis.__payloadText')), $expectedText];
        }
        foreach ($cases as $name => [$run, $expected]) {
            measure($run, $expected); // Warm-up excluded from results.
            $samples = [];
            for ($trial = 1; $trial <= $trials; $trial++) {
                $sample = ['case' => $name, 'mib' => $size / 1048576, 'trial' => $trial] + measure($run, $expected);
                $runs[] = $sample; $samples[] = $sample;
            }
            $row = ['case' => $name, 'mib' => $size / 1048576];
            foreach (['ms', 'cpu_ms', 'mib_s'] as $metric) { $row[$metric] = array_sum(array_column($samples, $metric)) / $trials; }
            $row['max_gap_ms'] = max(array_column($samples, 'max_gap_ms'));
            $summary[] = $row;
            if (!isset($options['json'])) { fwrite(STDERR, $name . ' ' . $row['mib'] . " MiB: SHA-256 PASS\n"); }
        }
    }
} finally { $browser?->close(); $client?->close(); }
$metadata = ['php' => PHP_VERSION, 'arch' => php_uname('m'), 'quickjs' => phpversion('php_quickjs'), 'bundle_sha256' => hash_file('sha256', dirname(__DIR__) . '/resources/puppeteer.js'), 'trials' => $trials, 'chrome' => $chromeVersion];
if (isset($options['json'])) { echo json_encode(['environment' => $metadata, 'runs' => $runs, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n"; }
else {
    echo json_encode($metadata, JSON_UNESCAPED_SLASHES), "\n";
    (new Table(new ConsoleOutput()))->setHeaders(['Case', 'MiB', 'Mean ms', 'Mean CPU ms', 'Mean MiB/s', 'Max loop gap ms'])->setRows(array_map(static fn(array $row): array => array_map(static fn($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, array_values($row)), $summary))->render();
    echo "Timing includes transfer and incremental SHA-256, excludes payload preparation. CPU is PHP + embedded QuickJS; Chrome is excluded. Loop gap uses a 1 ms timer. No result files are written.\n";
}
