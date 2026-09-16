<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\Puppeteer\Page;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

final class ReadableStreamTest extends TestCase
{
    private function client(): Client
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/resources/puppeteer.js');
        self::assertIsString($source);
        // Instrument the real shipped guest, as GuestFunctionLifetimeTest does.
        // Only a fake remote producer is added; encoding/dispatch/streaming stay real.
        self::assertSame(1, preg_match('/var ([\w$]+)=new Map,([\w$]+)=new WeakMap,([\w$]+)=0,([\w$]+)=0,/', $source, $match));
        $source = preg_replace('/globalThis\.__quickjsDispatch\s*=/', 'globalThis.__testStreamObjects=' . $match[1] . ';globalThis.__quickjsDispatch=', $source, 1);
        self::assertIsString($source);
        $file = tempnam(sys_get_temp_dir(), 'quickjs-stream-test-');
        self::assertIsString($file);
        file_put_contents($file, $source);
        try {
            $client = new Client($file);
        } finally {
            unlink($file);
        }
        $js = (new ReflectionProperty($client, 'js'))->getValue($client);
        $js->eval(<<<'JS'
        let cancelled = 0, pulls = 0;
        globalThis.__testStreamObjects.set(-1, {
          createPDFStream() { return this.stream('large'); },
          shared() { return this.saved ??= this.stream('large'); },
          stream(kind) {
            return new ReadableStream({
              pull(controller) {
                pulls++;
                if (kind === 'pending') return new Promise(() => {});
                if (kind === 'error') throw new Error('source failure');
                if (kind === 'invalid') { controller.enqueue('not bytes'); return; }
                const bytes = new Uint8Array(kind === 'large' ? 1048593 : 0);
                for (let i = 0; i < bytes.length; i++) bytes[i] = i % 256;
                controller.enqueue(bytes);
                controller.close();
              },
              cancel() { cancelled++; }
            }, {highWaterMark: 0});
          },
          stats() { return {cancelled, pulls}; },
          ping() { return 42; },
        });
        JS);

        return $client;
    }

    public function testChunksAreBoundedExactAndYieldToTheEventLoop(): void
    {
        $client = $this->client();
        try {
            $stream = (new Page($client, -1, 'Page'))->createPDFStream();
            self::assertInstanceOf(ReadableStream::class, $stream);
            self::assertSame(0, $client->call(-1, 'stats', [])->await()['pulls']);
            $closed = 0;
            $stream->onClose(static function () use (&$closed): void { ++$closed; });
            $ticks = 0;
            $timer = EventLoop::repeat(0, static function () use (&$ticks): void { ++$ticks; });
            $data = '';
            $chunks = 0;
            try {
                foreach ($stream as $chunk) {
                    self::assertLessThanOrEqual(65536, strlen($chunk));
                    $data .= $chunk;
                    ++$chunks;
                }
            } finally {
                EventLoop::cancel($timer);
            }
            self::assertSame(str_repeat(implode('', array_map(chr(...), range(0, 255))), 4096) . implode('', array_map(chr(...), range(0, 16))), $data);
            self::assertGreaterThanOrEqual($chunks, $ticks, 'Ready chunks must not starve the event loop');
            self::assertTrue($stream->isClosed());
            self::assertNull($stream->read());
            delay(0.001);
            self::assertSame(1, $closed);
            self::assertSame([], (new ReflectionProperty($client, 'streams'))->getValue($client));
        } finally {
            $client->close();
        }
    }

    public function testCancellationClosesOnlyStreamAndRejectsConcurrentRead(): void
    {
        $client = $this->client();
        try {
            $stream = $client->call(-1, 'stream', ['pending'])->await();
            $cancellation = new DeferredCancellation();
            $read = async(fn () => $stream->read($cancellation->getCancellation()));
            delay(0.01);
            try {
                $stream->read();
                self::fail('Expected PendingReadError');
            } catch (PendingReadError) {
                self::assertTrue(true);
            }
            $cancellation->cancel();
            try {
                $read->await();
                self::fail('Expected cancellation');
            } catch (CancelledException) {
                self::assertTrue(true);
            }
            delay(0.001);
            self::assertTrue($stream->isClosed());
            self::assertSame(1, $client->call(-1, 'stats', [])->await()['cancelled']);
            self::assertSame(42, $client->call(-1, 'ping', [])->await());
            self::assertSame([], (new ReflectionProperty($client, 'pending'))->getValue($client));
        } finally {
            $client->close();
        }
    }

    public function testCloseUnblocksReadAndFinalizerCancelsUnreadStream(): void
    {
        $client = $this->client();
        try {
            $stream = $client->call(-1, 'stream', ['pending'])->await();
            $read = async(fn () => $stream->read());
            delay(0.01);
            $stream->close();
            $stream->close();
            self::assertNull($read->await());
            $unread = $client->call(-1, 'stream', ['pending'])->await();
            unset($unread);
            gc_collect_cycles();
            delay(0.001);
            self::assertSame(2, $client->call(-1, 'stats', [])->await()['cancelled']);
            self::assertSame([], (new ReflectionProperty($client, 'streams'))->getValue($client));
        } finally {
            $client->close();
        }
    }

    public function testQueuedFinalizerDoesNotCancelRehydratedStream(): void
    {
        $client = $this->client();
        try {
            $stream = $client->call(-1, 'shared', [])->await();
            $same = $client->call(-1, 'shared', [])->await();
            self::assertSame($stream, $same);
            unset($stream, $same);
            // Re-encode synchronously before the queued destructor cancellation.
            $stream = $client->call(-1, 'shared', [])->await();
            self::assertSame(65536, strlen($stream->read()));
            $stream->close();
        } finally {
            $client->close();
        }
    }

    public function testSourceErrorsAndTransportShutdownReachReader(): void
    {
        $client = $this->client();
        try {
            foreach (['error' => 'source failure', 'invalid' => 'Uint8Array'] as $kind => $message) {
                $stream = $client->call(-1, 'stream', [$kind])->await();
                try {
                    $stream->read();
                    self::fail('Expected stream error');
                } catch (StreamException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                }
                self::assertTrue($stream->isClosed());
            }
            $stream = $client->call(-1, 'stream', ['pending'])->await();
            $read = async(fn () => $stream->read());
            delay(0.01);
            $client->close();
            try {
                $read->await();
                self::fail('Expected transport error');
            } catch (StreamException $error) {
                self::assertStringContainsString('closed', $error->getMessage());
            }
        } finally {
            $client->close();
        }
    }
}
