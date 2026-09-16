<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Amp\Process\Process;
use FilesystemIterator;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class InstallationTest extends TestCase
{
    public function testComposerConsumerInstallsWithoutDevDependenciesAndReusesBrowserFromApplicationRoot(): void
    {
        $root = dirname(__DIR__, 3);
        $app = sys_get_temp_dir() . '/puphpeteer-consumer-' . bin2hex(random_bytes(8));
        mkdir($app);
        $app = realpath($app) ?: $app;
        try {
            $snapshot = $app . '/package';
            foreach (['composer.json', 'src', 'resources', 'bin', 'upstream'] as $file) {
                self::copy($root . '/' . $file, $snapshot . '/' . $file);
            }
            $repositories = [['type' => 'path', 'url' => $snapshot, 'options' => ['symlink' => true, 'versions' => ['zoon/puphpeteer' => 'dev-fixture']]]];
            $installed = json_decode(file_get_contents($root . '/vendor/composer/installed.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
            foreach ($installed['packages'] as $package) {
                $repositories[] = isset($package['install-path'])
                    ? ['type' => 'path', 'url' => realpath($root . '/vendor/composer/' . $package['install-path']), 'options' => ['symlink' => true, 'versions' => [$package['name'] => $package['version']]]]
                    : ['type' => 'package', 'package' => array_intersect_key($package, array_flip(['name', 'version', 'type', 'require', 'provide', 'replace', 'conflict']))];
            }
            $repositories[] = ['packagist.org' => false];
            file_put_contents($app . '/composer.json', json_encode([
                'require' => ['zoon/puphpeteer' => 'dev-fixture'],
                'require-dev' => ['phpunit/phpunit' => '^11'],
                'repositories' => $repositories,
                'minimum-stability' => 'dev',
                'config' => ['allow-plugins' => false],
                'autoload' => ['psr-4' => ['Consumer\\' => 'src/']],
                'scripts' => [
                    'browser:install' => '@php vendor/bin/console browser:install',
                    'post-install-cmd' => '@browser:install',
                    'post-update-cmd' => '@browser:install',
                ],
            ], JSON_THROW_ON_ERROR));
            mkdir($app . '/src');
            file_put_contents($app . '/src/Marker.php', '<?php namespace Consumer; final class Marker {}');

            // An independently constructed path catches agreement on an incorrect installer/resolver path.
            $lock = json_decode(file_get_contents($root . '/upstream/lock.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
            $arm = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true);
            $platform = match (PHP_OS_FAMILY) {
                'Darwin' => $arm ? 'mac-arm64' : 'mac-x64',
                'Linux' => $arm ? 'linux-arm64' : 'linux64',
                default => PHP_INT_SIZE === 8 ? 'win64' : 'win32',
            };
            $binary = match (PHP_OS_FAMILY) {
                'Darwin' => 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
                'Windows' => 'chrome.exe',
                default => 'chrome',
            };
            $expected = $app . '/.chrome/' . $platform . '-' . $lock['package']['chromeBuildId'] . '/chrome-' . $platform . '/' . $binary;
            mkdir(dirname($expected), 0777, true);
            file_put_contents($expected, "#!/bin/sh\nexit 0\n");
            chmod($expected, 0755);

            // Composer invokes the application's post-update and post-install hooks, without downloading.
            $options = ['--no-dev', '--no-interaction', '--no-plugins', '--ignore-platform-req=ext-php_quickjs'];
            $update = self::runCommand(['composer', 'update', ...$options], $app);
            self::assertStringContainsString('[OK]', $update);
            $install = self::runCommand(['composer', 'install', ...$options], $app);
            self::assertStringContainsString('[OK]', $install);
            self::assertDirectoryDoesNotExist($app . '/vendor/phpunit/phpunit');
            self::assertDirectoryDoesNotExist($app . '/node_modules');
            self::assertStringContainsString('browser:install', self::runCommand([PHP_BINARY, 'vendor/bin/console', 'list', '--raw'], $app));

            $probe = <<<'PHP_CODE'
                require 'vendor/autoload.php';
                if (!class_exists(Consumer\Marker::class)) { exit(2); }
                echo Nesk\Puphpeteer\Internal\BrowserExecutable::resolve($argv[1]);
                PHP_CODE;
            self::assertSame($expected, self::runCommand([PHP_BINARY, '-r', $probe, $app . '/vendor/zoon/puphpeteer'], $app));
        } finally {
            ProcessRunner::removeDirectory($app);
        }
    }

    /** @param list<string> $command */
    private static function runCommand(array $command, string $cwd): string
    {
        $environment = getenv();
        foreach (['PUPPETEER_EXECUTABLE_PATH', 'PUPPETEER_CACHE_DIR', 'PUPPETEER_SKIP_DOWNLOAD', 'PUPPETEER_CHROME_SKIP_DOWNLOAD', 'PUPPETEER_SKIP_CHROME_DOWNLOAD'] as $key) {
            $environment[$key] = '';
        }
        $environment['COMPOSER_DISABLE_NETWORK'] = '1';
        $result = ProcessRunner::collect(Process::start($command, $cwd, $environment), 60);
        self::assertSame(0, $result['code'], $result['stdout'] . $result['stderr']);

        return $result['stdout'];
    }

    private static function copy(string $source, string $destination): void
    {
        if (is_dir($source)) {
            mkdir($destination, 0777, true);
            foreach (new FilesystemIterator($source) as $file) {
                if ($file instanceof SplFileInfo) {
                    self::copy($file->getPathname(), $destination . '/' . $file->getFilename());
                }
            }
        } else {
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0777, true);
            }
            copy($source, $destination);
        }
    }
}
