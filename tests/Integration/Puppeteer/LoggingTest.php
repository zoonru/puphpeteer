<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\ByteStream\buffer;

final class LoggingTest extends TestCase
{
    /** @psalm-pure */
    public static function closingMethods(): array
    {
        return [['close'], ['disconnect']];
    }

    #[DataProvider('closingMethods')]
    public function testSlowLogConsumerDoesNotBlockResultsOrEventLoop(string $closingMethod): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'quickjs-logging-');
        if (false === $bundle) {
            throw new RuntimeException('Cannot create fixture');
        }
        file_put_contents($bundle, <<<'JS'
            globalThis.__quickjsDispatch = (kind, payload) => {
              if (kind !== 'call') return;
              if (payload.method !== 'log') {
                __quickjsEmit('log', 'closed');
                __quickjsEmit('result', {id: payload.id, value: null});
                return;
              }
              for (let i = 0; i < 32; i++) __quickjsEmit('log', i + ':' + 'x'.repeat(16384));
              __quickjsEmit('result', {id: payload.id, value: 42});
            };
            JS);
        $process = null;
        try {
            $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
                require $argv[1];
                $client = new Nesk\Puphpeteer\Client($argv[2]);
                $result = $client->call(0, 'log', [])->await();
                Amp\delay(0.01);
                echo "ready:$result\n";
                $browser = new Nesk\Puphpeteer\Puppeteer\Browser($client, 1, 'Browser');
                $browser->{$argv[3]}();
                CODE, dirname(__DIR__, 3) . '/vendor/autoload.php', $bundle, $closingMethod]);
            $process->getStdin()->close();
            // Leave stderr unread until a timer and the result have both completed.
            self::assertSame("ready:42\n", $process->getStdout()->read(new TimeoutCancellation(5)));
            $logs = buffer($process->getStderr(), new TimeoutCancellation(5));
            self::assertSame(0, $process->join(new TimeoutCancellation(5)));
            $expected = '';
            for ($i = 0; $i < 32; ++$i) {
                $expected .= '[QuickJS] ' . $i . ':' . str_repeat('x', 16384) . "\n";
            }
            $expected .= "[QuickJS] closed\n";
            self::assertSame($expected, $logs, 'Queued logs must retain their order and complete contents');
        } finally {
            if (null !== $process) {
                if ($process->isRunning()) {
                    $process->kill();
                }
                $process->join(new TimeoutCancellation(5));
            }
            unlink($bundle);
        }
    }

    /** @psalm-pure */
    public static function unavailableReaders(): array
    {
        return [['stalled'], ['closed']];
    }

    #[DataProvider('unavailableReaders')]
    public function testUnavailableReaderKeepsQueueBoundedAndCloseTerminates(string $reader): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'quickjs-log-bound-');
        if (false === $bundle) {
            throw new RuntimeException('Cannot create fixture');
        }
        file_put_contents($bundle, <<<'JS'
            globalThis.__quickjsDispatch = (kind, request) => {
              if (kind !== 'call') return;
              for (let i=0;i<200;i++) __quickjsEmit('log', 'x'.repeat(16384));
              __quickjsEmit('result', {id:request.id,value:42});
            };
            JS);
        $process = Process::start([PHP_BINARY, '-r', <<<'CODE'
            require $argv[1];
            $client = new Nesk\Puphpeteer\Client($argv[2]);
            for ($i=0;$i<10;$i++) {
                if ($client->call(0, 'log', [])->await() !== 42) exit(2);
            }
            $counts = [count((new ReflectionProperty($client,'logs'))->getValue($client)),
                (new ReflectionProperty($client,'logBytes'))->getValue($client),
                (new ReflectionProperty($client,'droppedLogs'))->getValue($client)];
            $start=hrtime(true);
            $client->close();
            echo json_encode([$counts,(hrtime(true)-$start)/1e9]);
            CODE, dirname(__DIR__, 3) . '/vendor/autoload.php', $bundle]);
        try {
            if ('closed' === $reader) {
                $process->getStderr()->close();
            }
            // Never drain stderr: the child must terminate despite backpressure.
            $output = buffer($process->getStdout(), new TimeoutCancellation(4));
            self::assertSame(0, $process->join(new TimeoutCancellation(2)));
            [$counts, $elapsed] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertLessThanOrEqual(64, $counts[0]);
            self::assertLessThanOrEqual(1048576, $counts[1]);
            self::assertGreaterThan(0, $counts[2]);
            self::assertLessThan(2, $elapsed);
        } finally {
            if ($process->isRunning()) {
                $process->kill();
            }
            $process->join(new TimeoutCancellation(5));
            unlink($bundle);
        }
    }
}
