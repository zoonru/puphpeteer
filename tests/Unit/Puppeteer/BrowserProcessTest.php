<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\Internal\BrowserProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function Amp\ByteStream\splitLines;

final class BrowserProcessTest extends TestCase
{
    public function testCloseWaitsForProcessBeforeRemovingItsProfile(): void
    {
        $profile = sys_get_temp_dir() . '/puphpeteer-close-test-' . bin2hex(random_bytes(8));
        mkdir($profile, 0700);
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            require $argv[1];
            echo "ready";
            Amp\delay(0.1);
            // Simulate Chrome finishing a profile write after its close response.
            exit(file_put_contents($argv[2] . '/Preferences', '{}') === 2 ? 0 : 1);
            CODE, dirname(__DIR__, 3) . '/vendor/autoload.php', $profile]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        try {
            self::assertSame('ready', self::readLine($process));
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
            require $argv[1];
            $processGroupId = posix_setsid();
            $child = proc_open([PHP_BINARY, '-r', 'require $argv[1]; Amp\\delay(60);', $argv[1]], [STDIN, STDOUT, STDERR], $pipes);
            echo $processGroupId, ':', proc_get_status($child)['pid'], "\n";
            Amp\delay(60);
            CODE, dirname(__DIR__, 3) . '/vendor/autoload.php']);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        $processGroupId = 0;
        try {
            [$processGroupId, $pid] = array_map(intval(...), explode(':', self::readLine($process)));
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
            require $argv[1];
            $processGroupId = posix_setsid();
            $child = proc_open([PHP_BINARY, '-r', 'require $argv[1]; while (true) { file_put_contents($argv[2] . "/Preferences", "{}"); Amp\\delay(0.01); }', $argv[1], $argv[2], '--user-data-dir=' . $argv[2]], [STDIN, STDOUT, STDERR], $pipes);
            while (!is_file($argv[2] . '/Preferences')) { Amp\delay(0.001); }
            echo $processGroupId, ':', proc_get_status($child)['pid'], "\n";
            Amp\delay(60);
            CODE, dirname(__DIR__, 3) . '/vendor/autoload.php', $profile]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        $processGroupId = 0;
        $pid = 0;
        try {
            [$processGroupId, $pid] = array_map(intval(...), explode(':', self::readLine($process)));
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

    private static function readLine(Process $process): string
    {
        foreach (splitLines($process->getStdout(), new TimeoutCancellation(5)) as $line) {
            return $line;
        }

        return '';
    }
}
