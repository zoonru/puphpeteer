<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use Js\Callback;
use PHPUnit\Framework\TestCase;
use QuickJS;

final class BridgeTest extends TestCase
{
    public function testNativeQueuePreservesLargeBinaryPayloadAndDrainsMessages(): void
    {
        $js = new QuickJS();
        $send = $js->eval('(kind, value) => quickjs.postMessage([kind, value])');
        self::assertInstanceOf(Callback::class, $send);
        $value = "\0\xff\xfe" . str_repeat('x', 65_536);
        $send('binary', $value);
        self::assertSame([['binary', $value]], $js->drainMessages());
        self::assertSame([], $js->drainMessages());
    }

    public function testJobBudgetLeavesPendingWorkAndCanResume(): void
    {
        $js = new QuickJS();
        $start = $js->eval('() => {
            let count = 0;
            const next = () => {
                quickjs.postMessage(["count", ++count]);
                if (count < 5) Promise.resolve().then(next);
            };
            Promise.resolve().then(next);
        }');
        self::assertInstanceOf(Callback::class, $start);
        $start();
        self::assertSame(1, $js->executePendingJobs(1));
        self::assertTrue($js->hasPendingJobs());
        $js->executePendingJobs(100);
        self::assertFalse($js->hasPendingJobs());
        self::assertSame(
            [['count', 1], ['count', 2], ['count', 3], ['count', 4], ['count', 5]],
            $js->drainMessages(),
        );
    }
}
