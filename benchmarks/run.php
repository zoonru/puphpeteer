<?php

declare(strict_types=1);

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Process\Process;
use function Amp\async;
use function Amp\delay;
use Nesk\Puphpeteer\Tests\Browser\BrowserRunner;
use function Amp\ByteStream\buffer;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<array{pid:int,parent:int,rss:int,cpu:float}> */
function sample(int $rootPid): array
{
    $process = Process::start(['/bin/ps', '-axo', 'pid=,ppid=,rss=,time=']);
    $result = BrowserRunner::collect($process, 5);
    if ($result['code'] !== 0) { throw new RuntimeException('ps failed: ' . $result['stderr']); }
    $rows = [];
    foreach (explode("\n", trim($result['stdout'])) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) !== 4) { continue; }
        [$pid, $parent, $rss, $cpu] = $fields;
        $seconds = 0.0;
        foreach (explode(':', $cpu) as $part) { $seconds = $seconds * 60 + (float) $part; }
        $rows[] = ['pid' => (int) $pid, 'parent' => (int) $parent, 'rss' => (int) $rss * 1024, 'cpu' => $seconds];
    }
    $selected = [$rootPid => true];
    do {
        $changed = false;
        foreach ($rows as $row) {
            if (isset($selected[$row['parent']]) && !isset($selected[$row['pid']])) {
                $selected[$row['pid']] = true;
                $changed = true;
            }
        }
    } while ($changed);
    // /usr/bin/time is the root; only PHP and its descendants are measured.
    return array_values(array_filter($rows, static fn(array $row): bool => $row['pid'] !== $rootPid && isset($selected[$row['pid']])));
}

function runTrial(BrowserRunner $runner, int $trial, string $output): array
{
    $browser = $runner->launch();
    try {
        $endpoint = parse_url($browser->endpoint);
        $response = HttpClientBuilder::buildDefault()->request(new Request('http://' . $endpoint['host'] . ':' . $endpoint['port'] . '/json/version'));
        $version = json_decode(buffer($response->getBody()), true, 512, JSON_THROW_ON_ERROR)['Browser'];
        $child = Process::start(['/usr/bin/time', '-l', ...$runner->command(__DIR__ . '/benchmark.php')], environment: $runner->environment($browser));
        $active = $started = false;
        $measurement = null;
        $seen = $startCpu = [];
        $peakRss = $steadyPeakRss = $samples = 0;
        $pending = $samplingErrors = '';
        $stopSampler = false;
        $sampler = async(static function () use (&$stopSampler, $child, &$active, &$started, &$seen, &$peakRss, &$steadyPeakRss, &$samples, &$samplingErrors): void {
            while (!$stopSampler) {
                delay(0.025);
                if ($stopSampler) { break; }
                try {
                    $rows = sample($child->getPid());
                    $rss = array_sum(array_column($rows, 'rss'));
                    $peakRss = max($peakRss, $rss);
                    if ($active) { $steadyPeakRss = max($steadyPeakRss, $rss); $samples++; }
                    foreach ($rows as $row) { if ($active || !$started) { $seen[$row['pid']] = $row; } }
                } catch (Throwable $error) { $samplingErrors .= "\nSampler error: " . $error->getMessage() . "\n"; }
            }
        });
        try {
            $result = BrowserRunner::collect($child, 120, static function (string $chunk) use (&$pending, &$active, &$started, &$startCpu, &$seen, &$measurement): void {
                $pending .= $chunk;
                while (($end = strpos($pending, "\n")) !== false) {
                    $line = substr($pending, 0, $end);
                    $pending = substr($pending, $end + 1);
                    if ($line === 'BENCH_READY' && !$started) {
                        $active = $started = true;
                        foreach ($seen as $pid => $row) { $startCpu[$pid] = $row['cpu']; }
                    } elseif (str_starts_with($line, 'BENCH_RESULT ')) {
                        $measurement = json_decode(substr($line, 13), true, 512, JSON_THROW_ON_ERROR);
                        $active = false;
                    }
                }
            });
        } finally { $stopSampler = true; $sampler->await(); }
        $stderr = $result['stderr'] . $samplingErrors;
        file_put_contents("$output/raw/quickjs-$trial.log", $result['stdout'] . "\n" . $stderr);
        if ($result['code'] !== 0 || $measurement === null) {
            throw new RuntimeException("QuickJS trial $trial failed ({$result['code']}): " . substr($stderr, 0, 1500) . substr($result['stdout'], -1000));
        }
        $steadyCpu = 0.0;
        foreach ($seen as $pid => $row) { $steadyCpu += max(0, $row['cpu'] - ($startCpu[$pid] ?? 0)) * 1000; }
        $hasTime = preg_match('/([\d.]+) real\s+([\d.]+) user\s+([\d.]+) sys/', $stderr, $time);
        return [...$measurement, 'trial' => $trial, 'chrome' => $version, 'resources' => [
            'sampled_tree_peak_rss_bytes' => $peakRss,
            'sampled_steady_tree_peak_rss_bytes' => $steadyPeakRss,
            'sampled_steady_tree_cpu_ms' => $steadyCpu,
            'steady_samples' => $samples, 'sampling_interval_ms' => 25,
            'total_process_tree_cpu_ms' => $hasTime ? ((float) $time[2] + (float) $time[3]) * 1000 : null,
        ]];
    } finally { $browser->close(); }
}

try {
    if (PHP_OS_FAMILY !== 'Darwin') { throw new RuntimeException('Resource benchmark requires macOS /usr/bin/time -l.'); }
    $trials = filter_var(getenv('BENCH_TRIALS') ?: '5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($trials === false) { throw new InvalidArgumentException('BENCH_TRIALS must be a positive integer.'); }
    $output = __DIR__ . '/results/current';
    if (!is_dir("$output/raw") && !mkdir("$output/raw", 0777, true)) { throw new RuntimeException('Cannot create benchmark output directory'); }
    $cpu = BrowserRunner::collect(Process::start(['/usr/sbin/sysctl', '-n', 'machdep.cpu.brand_string']), 5);
    $runner = new BrowserRunner();
    $runs = [];
    try {
        for ($trial = 0; $trial < $trials; $trial++) {
            echo 'Running quickjs ', $trial + 1, '/', $trials, "\n";
            $run = runTrial($runner, $trial, $output);
            $runs[] = $run;
            file_put_contents("$output/benchmark.json", json_encode([
                'platform' => 'darwin', 'arch' => php_uname('m') === 'x86_64' ? 'x64' : php_uname('m'),
                'cpus' => trim($cpu['stdout']), 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'runs' => $runs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            echo json_encode(['backend' => 'quickjs', 'evaluate_ms' => $run['phases']['evaluate']['wall_ms'],
                'cpu_ms' => $run['resources']['total_process_tree_cpu_ms'],
                'rss_mb' => $run['resources']['sampled_tree_peak_rss_bytes'] / 1048576], JSON_THROW_ON_ERROR), "\n";
        }
    } finally { $runner->close(); }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
