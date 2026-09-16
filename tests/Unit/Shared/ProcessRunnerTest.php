<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Shared;

use Amp\CancelledException;
use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    public function testDrainsBothPipesAndPreservesExitCode(): void
    {
        $process = Process::start([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 262144)); echo "done"; exit(7);']);
        $chunks = '';
        $result = ProcessRunner::collect($process, 5, static function (string $chunk) use (&$chunks): void { $chunks .= $chunk; });
        self::assertSame(7, $result['code']);
        self::assertSame('done', $result['stdout']);
        self::assertSame('done', $chunks);
        self::assertSame(str_repeat('e', 262144), $result['stderr']);
    }

    public function testTimeoutKillsChildAndDescendant(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX process tree cleanup');
        }
        $ps = ProcessRunner::collect(Process::start(['/bin/ps', '-p', (string) getmypid()]), 5);
        if (0 !== $ps['code']) {
            self::markTestSkipped('Process inspection is unavailable in this sandbox');
        }
        $started = hrtime(true);
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            require $argv[1];
            $child = proc_open([PHP_BINARY, '-r', 'require $argv[1]; Amp\\delay(30);', $argv[1]], [STDIN, STDOUT, STDERR], $pipes);
            echo proc_get_status($child)['pid'], "\n";
            flush();
            Amp\delay(30);
            CODE, dirname(__DIR__, 3) . '/vendor/autoload.php']);
        $pid = '';
        try {
            ProcessRunner::collect($process, 0.3, static function (string $chunk) use (&$pid): void { $pid .= $chunk; });
            self::fail('Expected timeout');
        } catch (CancelledException) {
            self::assertLessThan(5.0, (float) (hrtime(true) - $started) / 1e9);
            self::assertFalse($process->isRunning());
            self::assertGreaterThan(0, (int) $pid);
            // An orphan may briefly remain a zombie, but must no longer execute.
            $status = ProcessRunner::collect(Process::start(['/bin/ps', '-o', 'stat=', '-p', trim($pid)]), 5);
            self::assertTrue('' === trim($status['stdout']) || str_starts_with(trim($status['stdout']), 'Z'));
        }
    }
}
