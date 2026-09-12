<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class HostStreamsTest extends TestCase
{
    public function testBundledReadableStreamsSupportPullErrorsAndCancellation(): void
    {
        $js = new \QuickJS();
        $js->register('now', static fn(): float => 0.0);
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/puppeteer.js');
        if ($source === false) { throw new \RuntimeException('Missing bundle'); }
        $js->eval($source);
        self::assertSame('Привет, 😀', $js->eval('new TextDecoder().decode(new TextEncoder().encode("Привет, 😀"))'));
        self::assertTrue($js->eval('(() => { try { new TextDecoder("utf-8", {fatal: true}).decode(new Uint8Array([0xc3, 0x28])); return false; } catch (_) { return true; } })()'));
        $dispatch = $js->eval(<<<'JS'
        () => {
          Promise.all([
            (async () => {
              let count = 0;
              const stream = new ReadableStream({
                async pull(controller) {
                  await Promise.resolve();
                  controller.enqueue(++count);
                  if (count === 3) controller.close();
                }
              });
              const reader = stream.getReader();
              const values = [];
              while (true) {
                const {value, done} = await reader.read();
                if (done) break;
                values.push(value);
              }
              reader.releaseLock();
              return values;
            })(),
            (async () => {
              const reader = new ReadableStream({pull() { throw new Error('stream failed'); }}).getReader();
              try { await reader.read(); return 'missing failure'; }
              catch (error) { return error.message; }
              finally { reader.releaseLock(); }
            })(),
            (async () => {
              let reason;
              const reader = new ReadableStream({cancel(value) { reason = value; }}).getReader();
              await reader.cancel('cancelled');
              const {done} = await reader.read();
              return [reason, done];
            })()
          ]).then(value => __quickjsEmit('result', value), error => __quickjsEmit('error', error.message));
        }
        JS);
        self::assertInstanceOf(\Js\Callback::class, $dispatch);
        $batch = $dispatch->dispatch([], 1000);
        self::assertFalse($batch['pending']);
        self::assertSame([['result', [[1, 2, 3], 'stream failed', ['cancelled', true]]]], $batch['messages']);
    }
}
