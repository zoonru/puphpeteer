<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BridgeTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        if (!class_exists(\QuickJS::class) || !method_exists(\Js\Callback::class, 'dispatch')) {
            throw new RuntimeException('Integration tests require php-quickjs with Js\\Callback::dispatch().');
        }
    }

    public function testBatchPreservesLargeBinaryPayloadAndDrainsMessages(): void
    {
        $js = new \QuickJS();
        $dispatch = $js->eval('(kind, value) => { __quickjsEmit(kind, value); }');
        self::assertInstanceOf(\Js\Callback::class, $dispatch);
        $value = "\0\xff\xfe" . str_repeat('x', 65_536);
        $batch = $dispatch->dispatch(['binary', $value]);
        self::assertSame([['binary', $value]], $batch['messages']);
        self::assertFalse($batch['pending']);
        self::assertSame([], $dispatch->dispatch(null)['messages']);
    }

    public function testJobBudgetLeavesPendingWorkAndCanResume(): void
    {
        $js = new \QuickJS();
        $dispatch = $js->eval('() => {
            let count = 0;
            const next = () => {
                __quickjsEmit("count", ++count);
                if (count < 5) Promise.resolve().then(next);
            };
            Promise.resolve().then(next);
        }');
        self::assertInstanceOf(\Js\Callback::class, $dispatch);
        $first = $dispatch->dispatch([], 1);
        self::assertSame(1, $first['jobs']);
        self::assertTrue($first['pending']);
        $rest = $dispatch->dispatch(null, 100);
        self::assertFalse($rest['pending']);
        self::assertSame(
            [['count', 1], ['count', 2], ['count', 3], ['count', 4], ['count', 5]],
            [...$first['messages'], ...$rest['messages']],
        );
    }
}
