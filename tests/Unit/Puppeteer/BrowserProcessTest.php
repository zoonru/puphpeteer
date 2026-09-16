<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\Internal\BrowserProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BrowserProcessTest extends TestCase
{
    public function testCloseWaitsForProcessBeforeRemovingItsProfile(): void
    {
        $profile = sys_get_temp_dir() . '/puphpeteer-close-test-' . bin2hex(random_bytes(8));
        mkdir($profile, 0700);
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            echo "ready";
            usleep(100000);
            // Simulate Chrome finishing a profile write after its close response.
            exit(file_put_contents($argv[1] . '/Preferences', '{}') === 2 ? 0 : 1);
            CODE, $profile]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        try {
            self::assertSame('ready', $process->getStdout()->read(new TimeoutCancellation(5)));
            $browser->close();
            self::assertSame(0, $process->join(), 'Process must finish its profile write, not be killed');
            self::assertDirectoryDoesNotExist($profile);
            $browser->close(); // Closing twice remains safe.
        } finally {
            if ($process->isRunning()) {
                $process->kill();
            }
            $process->join(new TimeoutCancellation(5));
            if (is_file($profile . '/Preferences')) {
                unlink($profile . '/Preferences');
            }
            if (is_dir($profile)) {
                rmdir($profile);
            }
        }
    }

    public function testForcedShutdownKillsDescendantsAndRemovesTemporaryProfile(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_exec') || !function_exists('posix_setsid') || !function_exists('posix_kill')) {
            self::markTestSkipped('POSIX process groups are unavailable');
        }
        $profile = sys_get_temp_dir() . '/puphpeteer-owned-test-' . bin2hex(random_bytes(8));
        mkdir($profile, 0700);
        file_put_contents($profile . '/Preferences', 'user data');
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            $processGroupId = posix_setsid();
            $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [STDIN, STDOUT, STDERR], $pipes);
            echo $processGroupId, ':', proc_get_status($child)['pid'], "\n";
            sleep(60);
            CODE]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        $processGroupId = 0;
        try {
            [$processGroupId, $pid] = array_map(intval(...), explode(':', trim($process->getStdout()->read(new TimeoutCancellation(5)) ?? '')));
            $reflection->getProperty('processGroupId')->setValue($browser, $processGroupId);
            self::assertGreaterThan(0, $pid);
            $start = hrtime(true);
            $browser->close();
            self::assertLessThan(12, (float) (hrtime(true) - $start) / 1e9);
            self::assertFalse($process->isRunning());
            self::assertFalse(posix_kill(-$processGroupId, 0), 'Process group still exists');
            self::assertDirectoryDoesNotExist($profile);
        } finally {
            if ($process->isRunning()) {
                $process->kill();
            }
            $process->join(new TimeoutCancellation(5));
            if ($processGroupId > 0) {
                posix_kill(-$processGroupId, 9);
            }
            if (is_file($profile . '/Preferences')) {
                unlink($profile . '/Preferences');
            }
            if (is_dir($profile)) {
                rmdir($profile);
            }
        }
    }

    public function testCloseKillsReparentedChromeProcessesBeforeRemovingProfile(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('pcntl_exec') || !function_exists('posix_setsid') || !function_exists('posix_kill')) {
            self::markTestSkipped('POSIX process groups are unavailable');
        }
        $profile = sys_get_temp_dir() . '/puphpeteer-reparented-test-' . bin2hex(random_bytes(8));
        mkdir($profile, 0700);
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            $processGroupId = posix_setsid();
            $child = proc_open([PHP_BINARY, '-r', 'while (true) { file_put_contents($argv[1] . "/Preferences", "{}"); usleep(10000); }', $argv[1], '--user-data-dir=' . $argv[1]], [STDIN, STDOUT, STDERR], $pipes);
            while (!is_file($argv[1] . '/Preferences')) { usleep(1000); }
            echo $processGroupId, ':', proc_get_status($child)['pid'], "\n";
            sleep(60);
            CODE, $profile]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        $processGroupId = 0;
        $pid = 0;
        try {
            [$processGroupId, $pid] = array_map(intval(...), explode(':', trim($process->getStdout()->read(new TimeoutCancellation(5)) ?? '')));
            $reflection->getProperty('processGroupId')->setValue($browser, $processGroupId);
            self::assertGreaterThan(0, $pid);
            $process->kill();
            $process->join(new TimeoutCancellation(5));
            $browser->close();
            self::assertFalse(posix_kill(-$processGroupId, 0), 'Reparented process group still exists');
            self::assertDirectoryDoesNotExist($profile);
        } finally {
            if ($process->isRunning()) {
                $process->kill();
                $process->join(new TimeoutCancellation(5));
            }
            if ($pid > 0) {
                posix_kill($pid, 9);
            }
            if ($processGroupId > 0) {
                posix_kill(-$processGroupId, 9);
            }
            if (is_file($profile . '/Preferences')) {
                unlink($profile . '/Preferences');
            }
            if (is_dir($profile)) {
                rmdir($profile);
            }
        }
    }
}
