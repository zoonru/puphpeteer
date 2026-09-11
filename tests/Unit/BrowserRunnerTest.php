<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit;

use Amp\CancelledException;
use Amp\Process\Process;
use Nesk\Puphpeteer\Tests\Browser\BrowserRunner;
use PHPUnit\Framework\TestCase;

final class BrowserRunnerTest extends TestCase
{
    public function testDrainsBothPipesAndPreservesExitCode(): void
    {
        $process = Process::start([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 262144)); echo "done"; exit(7);']);
        $chunks = '';
        $result = BrowserRunner::collect($process, 5, static function (string $chunk) use (&$chunks): void { $chunks .= $chunk; });
        self::assertSame(7, $result['code']);
        self::assertSame('done', $result['stdout']);
        self::assertSame('done', $chunks);
        self::assertSame(str_repeat('e', 262144), $result['stderr']);
    }

    public function testTimeoutKillsChildAndDescendant(): void
    {
        if (PHP_OS_FAMILY === 'Windows') { self::markTestSkipped('POSIX process tree cleanup'); }
        $ps = BrowserRunner::collect(Process::start(['/bin/ps', '-p', (string) getmypid()]), 5);
        if ($ps['code'] !== 0) { self::markTestSkipped('Process inspection is unavailable in this sandbox'); }
        $started = hrtime(true);
        $process = Process::start([PHP_BINARY, '-r', '$p = proc_open([PHP_BINARY, "-r", "sleep(30);"], [STDIN, STDOUT, STDERR], $pipes); echo proc_get_status($p)["pid"], "\\n"; flush(); sleep(30);']);
        $pid = '';
        try {
            BrowserRunner::collect($process, 0.3, static function (string $chunk) use (&$pid): void { $pid .= $chunk; });
            self::fail('Expected timeout');
        } catch (CancelledException) {
            self::assertLessThan(5.0, (float) (hrtime(true) - $started) / 1e9);
            self::assertFalse($process->isRunning());
            self::assertGreaterThan(0, (int) $pid);
            // An orphan may briefly remain a zombie, but must no longer execute.
            $status = BrowserRunner::collect(Process::start(['/bin/ps', '-o', 'stat=', '-p', trim($pid)]), 5);
            self::assertTrue(trim($status['stdout']) === '' || str_starts_with(trim($status['stdout']), 'Z'));
        }
    }
}
