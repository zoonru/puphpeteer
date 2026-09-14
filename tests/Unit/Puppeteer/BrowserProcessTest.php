<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\Internal\BrowserProcess;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;
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
        $profile = sys_get_temp_dir() . '/puphpeteer-owned-test-' . bin2hex(random_bytes(8));
        mkdir($profile, 0700);
        file_put_contents($profile . '/Preferences', 'user data');
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [STDIN, STDOUT, STDERR], $pipes);
            echo proc_get_status($child)['pid'], "\n";
            sleep(60);
            CODE]);
        $reflection = new ReflectionClass(BrowserProcess::class);
        $browser = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('process')->setValue($browser, $process);
        $reflection->getProperty('temporaryProfile')->setValue($browser, $profile);
        try {
            $pid = trim($process->getStdout()->read(new TimeoutCancellation(5)) ?? '');
            self::assertGreaterThan(0, (int) $pid);
            $start = hrtime(true);
            $browser->close();
            self::assertLessThan(12, (float) (hrtime(true) - $start) / 1e9);
            self::assertFalse($process->isRunning());
            $status = ProcessRunner::collect(Process::start(['/bin/ps', '-o', 'stat=', '-p', $pid]), 5);
            self::assertTrue('' === trim($status['stdout']) || str_starts_with(trim($status['stdout']), 'Z'), 'Descendant still running');
            self::assertDirectoryDoesNotExist($profile);
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
}
