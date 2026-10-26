<?php

declare(strict_types=1);

// Reference checkouts and dependencies are isolated from the application.
$options = getopt('', ['destination:', 'extension-source:']);
$root = dirname(__DIR__);
$destination = $options['destination'] ?? getenv('PUPPETEER_BENCHMARK_REFERENCE') ?: $root . '/.cache/benchmarks/reference';
$versions = json_decode(file_get_contents(__DIR__ . '/versions.json'), true, 512, JSON_THROW_ON_ERROR);
$run = static function (array $command, string $directory): void {
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, [...getenv(), 'PUPPETEER_SKIP_DOWNLOAD' => 'true']);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException('Reference preparation failed: ' . implode(' ', $command));
    }
};
$checkout = static function (array $version, string $path) use ($run): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create ' . $path);
    }
    if (isset($version['archive'])) {
        $archive = __DIR__ . '/' . $version['archive'];
        if (hash_file('sha256', $archive) !== $version['sha256']) {
            throw new RuntimeException('Reference archive checksum mismatch');
        }
        $run(['tar', '-xf', $archive, '-C', $path], $path);

        return;
    }
    if (!is_dir($path . '/.git')) {
        $run(['git', 'init', '--quiet'], $path);
        $run(['git', 'remote', 'add', 'origin', $version['repository']], $path);
    }
    $run(['git', 'fetch', '--depth=1', 'origin', $version['ref']], $path);
    $run(['git', 'checkout', '--force', '--detach', 'FETCH_HEAD'], $path);
};
foreach ($versions['clients'] as $backend => $version) {
    $checkout($version, $destination . '/' . $backend);
}
if (isset($options['extension-source'])) {
    $checkout($versions['quickjs'], $options['extension-source']);
}
$project = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['rialto', 'quickjs'] as $backend) {
    $path = $destination . '/' . $backend;
    $composer = json_decode(file_get_contents($path . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($project['require'] as $name => $constraint) {
        if (str_starts_with($name, 'amphp/')) {
            $composer['require'][$name] = $constraint;
        }
    }
    unset($composer['require-dev'], $composer['scripts'], $composer['autoload-dev']);
    file_put_contents($path . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    $run(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--prefer-dist'], $path);
}
file_put_contents($destination . '/rialto/package.json', json_encode(['private' => true, 'dependencies' => [
    '@zoon/rialto' => $versions['rialto_node'],
    'puppeteer' => $versions['puppeteer'],
]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$run(['npm', 'install', '--ignore-scripts', '--no-audit', '--no-fund'], $destination . '/rialto');
file_put_contents($destination . '/versions.json', json_encode($versions, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
echo "Reference clients ready in $destination\n";
