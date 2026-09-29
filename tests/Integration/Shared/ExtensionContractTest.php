<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use Closure;
use Fiber;
use Js\Callback;
use PHPUnit\Framework\TestCase;
use QuickJS;
use QuickJSTimeoutException;
use stdClass;
use Throwable;
use WeakReference;

final class ExtensionContractTest extends TestCase
{
    public function testMessagesAreSnapshottedAndDataOnly(): void
    {
        $js = new QuickJS();
        $send = $this->jsCallback($js, '(value) => quickjs.postMessage(value)');
        $value = [null, true, false, 42, 1.25, "nul\0Привет", "\xff\xfe", [], ['name' => 'value']];
        $send($value);
        self::assertSame([$value], $js->drainMessages());
        $js->eval('const value = { nested: { x: 1 } }; quickjs.postMessage(value); value.nested.x = 2');
        self::assertSame([['nested' => ['x' => 1]]], $js->drainMessages());
        $this->assertRejected(static fn () => $js->eval('quickjs.postMessage(() => 1)'));
        self::assertSame([], $js->drainMessages());
    }

    public function testOverflowRejectsOnlyItsOperation(): void
    {
        $js = new QuickJS(maxQueuedMessageBytes: 256);
        $js->eval('quickjs.postMessage(1)');
        $this->assertRejected(static fn () => $js->eval('quickjs.postMessage(2)'));
        self::assertSame([1], $js->drainMessages());
        $js->eval('quickjs.postMessage(3)');
        self::assertSame([3], $js->drainMessages());
    }

    public function testCallbacksAndHandlesReleaseTheirReferences(): void
    {
        $js = new QuickJS();
        $baseline = $js->eval('__jsFnCount()');
        $callback = $this->jsCallback($js, '() => 42');
        self::assertSame($baseline + 1, $js->eval('__jsFnCount()'));
        unset($callback);
        self::assertSame($baseline, $js->eval('__jsFnCount()'));
        $resource = new stdClass();
        $weak = WeakReference::create($resource);
        $handle = $js->grant($resource);
        unset($resource);
        self::assertNotNull($weak->get());
        self::assertSame($weak->get(), $js->resolve($handle));
        self::assertTrue($js->revoke($handle));
        self::assertNull($weak->get());
    }

    public function testCallbackMovesBetweenFibersOutsideNativeCalls(): void
    {
        $js = new QuickJS();
        $send = $this->jsCallback($js, '(value) => quickjs.postMessage(value)');
        $fiber = new Fiber(static function () use ($send): void {
            $send(1);
            Fiber::suspend();
            $send(3);
        });
        $fiber->start();
        $send(2);
        $fiber->resume();
        self::assertSame([1, 2, 3], $js->drainMessages());
    }

    public function testExistingTimeoutStillBoundsSynchronousCalls(): void
    {
        $js = new QuickJS(timeoutMs: 25);
        $loop = $this->jsCallback($js, '() => { while (true) {} }');
        try {
            $loop();
            self::fail('Expected a native timeout.');
        } catch (QuickJSTimeoutException) {
            self::assertSame(42, $js->eval('42'));
        }
    }

    private function jsCallback(QuickJS $js, string $source): Callback
    {
        $callback = $js->eval($source);
        self::assertInstanceOf(Callback::class, $callback);

        return $callback;
    }

    private function assertRejected(Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable $error) {
            self::assertNotSame('', $error->getMessage());

            return;
        }
        self::fail('Expected the extension to reject the operation.');
    }
}
