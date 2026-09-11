<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/BrowserTestCase.php';

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Nesk\Puphpeteer\Internal\TargetClosedException;

final class AsyncLifecycleTest extends BrowserTestCase
{
    public function testEvaluationRunsConcurrentlyAcrossPages(): void
    {
        $other = $this->context->newPage()->await();
        $a = $this->page->evaluate('new Promise(resolve => { globalThis.finishTest = resolve; })');
        $b = $other->evaluate('new Promise(resolve => { globalThis.finishTest = resolve; })');
        $deadline = new \Amp\TimeoutCancellation(5);
        // Controller operations must complete while both browser promises remain pending.
        self::assertSame('function', $this->page->evaluate('typeof finishTest')->await($deadline));
        self::assertSame('function', $other->evaluate('typeof finishTest')->await($deadline));
        self::assertFalse($a->isComplete());
        self::assertFalse($b->isComplete());
        $other->evaluate('finishTest(42)')->await($deadline);
        self::assertSame(42, $b->await($deadline));
        self::assertFalse($a->isComplete());
        $this->page->evaluate('finishTest(21)')->await($deadline);
        self::assertSame(21, $a->await($deadline));
    }

    public function testNavigationCancellationReleasesWaitersAndPageRemainsUsable(): void
    {
        $cancel = new DeferredCancellation();
        $navigation = $this->page->goto($this->url('/hang'), ['signal' => $cancel->getCancellation()]);
        $this->waitForRequest('/hang');
        $cancel->cancel();
        try {
            $navigation->await();
            self::fail('Cancellation must reject navigation.');
        } catch (CancelledException) {
            self::assertTrue($cancel->getCancellation()->isRequested());
        }
        $this->page->goto($this->url('/empty.html'))->await();
        self::assertSame(42, $this->page->evaluate('42')->await());
    }

    public function testClosingContextRejectsPendingEvaluationAndReleasesItsTargets(): void
    {
        $connection = self::property($this->browser, 'connection');
        $contextId = self::property($this->context, 'id');
        $waiting = $this->page->evaluate('new Promise(() => {})');
        \Amp\delay(0.02);
        $this->context->close()->await();
        try {
            $waiting->await();
            self::fail('Closing a context must reject its pending evaluation.');
        } catch (TargetClosedException) {
            $result = $connection->send('Target.getTargets')->await();
            self::assertSame([], array_values(array_filter($result['targetInfos'], static fn (array $target): bool => ($target['browserContextId'] ?? null) === $contextId)));
        }
    }
}
