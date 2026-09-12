<?php

declare(strict_types=1);

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Process\Process;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Tests\Support\ProcessRunner;
use function Amp\async;
use function Amp\delay;
use function Amp\ByteStream\buffer;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return list<array{pid:int,parent:int,rss:int,cpu:float}> */
function sample(int $rootPid): array
{
    $process = Process::start(['/bin/ps', '-axo', 'pid=,ppid=,rss=,time=']);
    $result = ProcessRunner::collect($process, 5);
    if ($result['code'] !== 0) { throw new RuntimeException('ps failed: ' . $result['stderr']); }
    $rows = [];
    foreach (explode("\n", trim($result['stdout'])) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) !== 4) { continue; }
        [$pid, $parent, $rss, $cpu] = $fields;
        $seconds = $daySeconds = 0.0;
        if (str_contains($cpu, '-')) { [$days, $cpu] = explode('-', $cpu, 2); $daySeconds = (float) $days * 86400; }
        foreach (explode(':', $cpu) as $part) { $seconds = $seconds * 60 + (float) $part; }
        $rows[] = ['pid' => (int) $pid, 'parent' => (int) $parent, 'rss' => (int) $rss * 1024, 'cpu' => $daySeconds + $seconds];
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

function runTrial(int $trial, string $output, string $extension): array
{
    $extensionHash = hash_file('sha256', $extension);
    $bundleHash = hash_file('sha256', dirname(__DIR__) . '/resources/puppeteer.js');
    $browser = (new Puppeteer())->launch(['headless' => true, 'args' => ['--no-proxy-server']]);
    try {
        $endpoint = parse_url($browser->wsEndpoint());
        $response = HttpClientBuilder::buildDefault()->request(new Request('http://' . $endpoint['host'] . ':' . $endpoint['port'] . '/json/version'));
        $version = json_decode(buffer($response->getBody()), true, 512, JSON_THROW_ON_ERROR)['Browser'];
        $php = getenv('PHP_BIN') ?: PHP_BINARY;
        $command = [$php, '-n', '-d', 'extension=' . $extension, __DIR__ . '/benchmark.php'];
        $child = Process::start(['/usr/bin/time', ...(PHP_OS_FAMILY === 'Darwin' ? ['-l'] : ['-f', 'PUPHPETEER_TIME %e %U %S']), ...$command], environment: [...getenv(), 'BROWSER_WS' => $browser->wsEndpoint(), 'LC_ALL' => 'C']);
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
            $result = ProcessRunner::collect($child, 120, static function (string $chunk) use (&$pending, &$active, &$started, &$startCpu, &$seen, &$measurement): void {
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
        if ($extensionHash !== hash_file('sha256', $extension) || $bundleHash !== hash_file('sha256', dirname(__DIR__) . '/resources/puppeteer.js')) {
            throw new RuntimeException('Extension or bundle changed during trial; freeze build artifacts before benchmarking.');
        }
        if ($samplingErrors !== '' || $samples === 0) { throw new RuntimeException('Resource sampler failed or collected no steady-state samples; inspect raw benchmark logs.'); }
        $steadyCpu = 0.0;
        foreach ($seen as $pid => $row) { $steadyCpu += max(0, $row['cpu'] - ($startCpu[$pid] ?? 0)) * 1000; }
        $hasTime = preg_match(PHP_OS_FAMILY === 'Darwin' ? '/([\d.]+) real\s+([\d.]+) user\s+([\d.]+) sys/' : '/PUPHPETEER_TIME ([\d.]+) ([\d.]+) ([\d.]+)/', $stderr, $time);
        if (!$hasTime) { throw new RuntimeException('Cannot parse /usr/bin/time output; inspect raw benchmark logs.'); }
        return [...$measurement, 'extension_sha256' => $extensionHash, 'bundle_sha256' => $bundleHash, 'trial' => $trial, 'chrome' => $version, 'resources' => [
            'sampled_tree_peak_rss_bytes' => $peakRss,
            'sampled_steady_tree_peak_rss_bytes' => $steadyPeakRss,
            'sampled_steady_tree_cpu_ms' => $steadyCpu,
            'steady_samples' => $samples, 'sampling_interval_ms' => 25,
            'total_process_tree_cpu_ms' => $hasTime ? ((float) $time[2] + (float) $time[3]) * 1000 : null,
        ]];
    } finally { $browser->close(); }
}

try {
    if (!in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true)) { throw new RuntimeException('Resource benchmark requires macOS or Linux with /usr/bin/time and ps.'); }
    $extension = getenv('QUICKJS_EXTENSION');
    if (!is_string($extension) || $extension === '' || !is_file($extension) || !is_readable($extension)) {
        throw new RuntimeException('Set QUICKJS_EXTENSION to a readable extension file. See docs/quickjs.md.');
    }
    $trials = filter_var(getenv('BENCH_TRIALS') === false ? '5' : getenv('BENCH_TRIALS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($trials === false) { throw new InvalidArgumentException('BENCH_TRIALS must be a positive integer.'); }
    $output = __DIR__ . '/results/current';
    if (!is_dir("$output/raw") && !mkdir("$output/raw", 0777, true)) { throw new RuntimeException('Cannot create benchmark output directory'); }
    $cpu = PHP_OS_FAMILY === 'Darwin' ? trim(ProcessRunner::collect(Process::start(['/usr/sbin/sysctl', '-n', 'machdep.cpu.brand_string']), 5)['stdout']) : php_uname('m');
    $runs = [];
        for ($trial = 0; $trial < $trials; $trial++) {
            echo 'Running quickjs ', $trial + 1, '/', $trials, "\n";
            $run = runTrial($trial, $output, $extension);
            $runs[] = $run;
            file_put_contents("$output/benchmark.json", json_encode([
                'platform' => strtolower(PHP_OS_FAMILY), 'arch' => php_uname('m') === 'x86_64' ? 'x64' : php_uname('m'),
                'cpus' => $cpu, 'bundle_sha256' => $run['bundle_sha256'],
                'extension_sha256' => $run['extension_sha256'],
                'manifest' => json_decode(file_get_contents(dirname(__DIR__) . '/resources/manifest.json'), true, 512, JSON_THROW_ON_ERROR),
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'runs' => $runs,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            echo json_encode([
                'backend' => 'quickjs',
                'trial' => $trial + 1,
                'setup_ms' => $run['setup_ms'],
                'phases' => $run['phases'],
                'resources' => $run['resources'],
                // Keep the compact fields for scripts consuming older output.
                'evaluate_ms' => $run['phases']['evaluate']['wall_ms'],
                'cpu_ms' => $run['resources']['total_process_tree_cpu_ms'],
                'rss_mb' => $run['resources']['sampled_tree_peak_rss_bytes'] / 1048576,
            ], JSON_THROW_ON_ERROR), "\n";
        }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
