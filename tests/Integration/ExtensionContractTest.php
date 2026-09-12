<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration;

use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionContractTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!extension_loaded('php_quickjs') || !method_exists(\Js\Callback::class, 'dispatch')) {
            self::fail('Load the hardened php-quickjs fork to run the extension contract tests.');
        }
    }

    public function testDirectValuesDoNotAcquireMsgpackTagSemantics(): void
    {
        $js = new \QuickJS();
        $dispatch = $this->jsCallback($js, '(value) => __quickjsEmit("data", value)');
        $value = [null, true, false, 42, 1.25, 9_007_199_254_740_991, "nul\0Привет", "\xff\xfe", [], ['name' => 'value'], ['$__jsfn' => 12]];
        self::assertSame([['data', $value]], $dispatch->dispatch([$value])['messages']);
        $types = $this->jsCallback($js, '(text, bytes) => __quickjsEmit("types", [typeof text, bytes instanceof Uint8Array])');
        self::assertSame([['types', ['string', true]]], $types->dispatch(['hello', "\xff"])['messages']);
        $values = $this->jsCallback($js, '() => __quickjsEmit("data", [undefined, 2.0, new Uint8Array([0,255]), {}])');
        self::assertSame([['data', [null, 2, "\0\xff", []]]], $values->dispatch([])['messages']);
    }

    public function testNullPumpsWithoutCallingAndCallbackReturnIsIgnored(): void
    {
        $js = new \QuickJS();
        $dispatch = $this->jsCallback($js, '() => { __quickjsEmit("called", true); return () => 1; }');
        self::assertSame(['messages' => [], 'jobs' => 0, 'pending' => false], $dispatch->dispatch(null));
        self::assertSame([['called', true]], $dispatch->dispatch([])['messages']);
    }

    /**
     * @return iterable<string, array{string}>
     * @psalm-mutation-free
     */
    public static function rejectedOutput(): iterable
    {
        yield 'partial failure' => ['__quickjsEmit("partial", 1); throw new Error("failed")'];
        yield 'cyclic value' => ['const value = {}; value.self = value; __quickjsEmit("cycle", value)'];
        yield 'function' => ['__quickjsEmit("function", () => 1)'];
        yield 'symbol' => ['__quickjsEmit("symbol", Symbol("test"))'];
        yield 'getter failure' => ['__quickjsEmit("getter", { get value() { throw new Error("getter failed") } })'];
        yield 'value budget' => ['__quickjsEmit("bytes", new Uint8Array(16777217))'];
        yield 'queue count' => ['for (let i = 0; i < 4097; i++) __quickjsEmit("item", i)'];
        yield 'queue bytes' => ['const value = new Uint8Array(12000000); for (let i = 0; i < 3; i++) __quickjsEmit("bytes", value)'];
    }

    #[DataProvider('rejectedOutput')]
    public function testFailedBatchDiscardsOutputAndEngineRecovers(string $body): void
    {
        $js = new \QuickJS(timeoutMs: 2000);
        $bad = $this->jsCallback($js, "() => { $body }");
        $this->assertRejected(static fn() => $bad->dispatch([]));
        $good = $this->jsCallback($js, '() => __quickjsEmit("ok", 42)');
        self::assertSame([], $good->dispatch(null)['messages']);
        self::assertSame([['ok', 42]], $good->dispatch([])['messages']);
    }

    public function testInvalidArgumentsAndDepthCannotPoisonNextBatch(): void
    {
        $js = new \QuickJS();
        $dispatch = $this->jsCallback($js, '(value) => __quickjsEmit("ok", value)');
        $this->assertRejected(static fn() => $dispatch->dispatch([], 0));
        // Reflection deliberately bypasses the documented list type to test native validation.
        $method = new \ReflectionMethod($dispatch, 'dispatch');
        $this->assertRejected(static fn() => $method->invoke($dispatch, ['named' => 1]));
        $deep = 1;
        for ($i = 0; $i < 66; ++$i) { $deep = [$deep]; }
        $this->assertRejected(static fn() => $dispatch->dispatch([$deep]));
        self::assertSame([['ok', 42]], $dispatch->dispatch([42])['messages']);
    }

    public function testCallbacksAndHandlesReleaseTheirReferences(): void
    {
        $js = new \QuickJS();
        $baseline = $js->eval('__jsFnCount()');
        $callback = $this->jsCallback($js, '() => 42');
        self::assertSame($baseline + 1, $js->eval('__jsFnCount()'));
        unset($callback);
        self::assertSame($baseline, $js->eval('__jsFnCount()'));
        $resource = new \stdClass();
        $weak = \WeakReference::create($resource);
        $handle = $js->grant($resource);
        unset($resource);
        self::assertNotNull($weak->get());
        self::assertSame($weak->get(), $js->resolve($handle));
        self::assertTrue($js->revoke($handle));
        self::assertNull($weak->get());
        self::assertFalse($js->revoke($handle));
        $this->assertRejected(static fn() => $js->resolve($handle));
    }

    public function testDiscardedCallbackIsReleasedDuringDispatchWithoutAnotherEval(): void
    {
        $js = new \QuickJS();
        $dispatch = $this->jsCallback($js, '() => __quickjsEmit("retained", __jsFnCount())');
        $temporary = $this->jsCallback($js, '() => 42');
        self::assertSame([['retained', 2]], $dispatch->dispatch([])['messages']);
        unset($temporary);
        self::assertSame([['retained', 1]], $dispatch->dispatch([])['messages']);
    }

    public function testEngineMovesBetweenFiberStacksOnlyOutsideNativeCalls(): void
    {
        $js = new \QuickJS();
        $dispatch = $this->jsCallback($js, '(value) => __quickjsEmit("value", value)');
        $fiber = new Fiber(static function () use ($dispatch): array {
            $first = $dispatch->dispatch([1]);
            Fiber::suspend($first);
            return $dispatch->dispatch([3]);
        });
        self::assertSame([['value', 1]], $fiber->start()['messages']);
        self::assertSame([['value', 2]], $dispatch->dispatch([2])['messages']);
        $fiber->resume();
        self::assertSame([['value', 3]], $fiber->getReturn()['messages']);
        $js->register('suspend', static fn() => Fiber::suspend());
        $bad = $this->jsCallback($js, '() => php.suspend()');
        $suspending = new Fiber(function () use ($bad): void {
            $this->assertRejected(static fn() => $bad->dispatch([]));
            Fiber::suspend('outside');
        });
        self::assertSame('outside', $suspending->start());
        $suspending->resume();
        $js->register('reenter', static fn() => $dispatch->dispatch(null));
        $nested = $this->jsCallback($js, '() => php.reenter()');
        $this->assertRejected(static fn() => $nested->dispatch([]));
        self::assertSame([['value', 4]], $dispatch->dispatch([4])['messages']);
    }

    public function testTimeoutBoundsSynchronousDispatchAndEngineRecovers(): void
    {
        $js = new \QuickJS(timeoutMs: 25);
        $loop = $this->jsCallback($js, '() => { while (true) {} }');
        try {
            $loop->dispatch([]);
            self::fail('Expected a native timeout.');
        } catch (\QuickJSTimeoutException) {
            self::assertSame(42, $js->eval('42'));
        }
    }

    private function jsCallback(\QuickJS $js, string $source): \Js\Callback
    {
        $callback = $js->eval($source);
        self::assertInstanceOf(\Js\Callback::class, $callback);
        return $callback;
    }

    private function assertRejected(\Closure $operation): void
    {
        try { $operation(); } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
            return;
        }
        self::fail('Expected the extension to reject the operation.');
    }
}
