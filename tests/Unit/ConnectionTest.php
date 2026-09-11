<?php

declare(strict_types=1);

namespace Tests\Unit;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Nesk\Puphpeteer\ConnectionTransport;
use Nesk\Puphpeteer\Internal\Connection;
use Nesk\Puphpeteer\Internal\ProtocolException;
use Nesk\Puphpeteer\Internal\TargetClosedException;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

final class ConnectionTest extends TestCase
{
    private ConnectionTransport $transport;
    private Connection $connection;
    private array $sent = [];
    private int $closes = 0;

    protected function setUp(): void
    {
        $this->transport = new ConnectionTransport(function (string $message): void {
            $this->sent[] = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        }, function (): void { ++$this->closes; });
        $this->connection = new Connection($this->transport, 0.1);
    }

    protected function tearDown(): void { $this->connection->close(); }

    private function deliver(array $data): void
    {
        ($this->transport->onmessage)(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testRoutesConcurrentResponsesByIdAndSession(): void
    {
        $first = $this->connection->session('a')->send('Runtime.evaluate');
        $second = $this->connection->session('b')->send('Runtime.evaluate');
        delay(0);
        self::assertCount(2, $this->sent);
        $this->deliver(['id' => 2, 'sessionId' => 'b', 'result' => ['value' => 2]]);
        $this->deliver(['id' => 1, 'sessionId' => 'a', 'result' => ['value' => 1]]);
        self::assertSame(['value' => 1], $first->await());
        self::assertSame(['value' => 2], $second->await());
    }

    public function testEventWaiterRegistersBeforeActionAndIsSessionScoped(): void
    {
        $cancellation = new DeferredCancellation();
        $event = $this->connection->session('a')->waitFor('Page.loadEventFired', cancellation: $cancellation->getCancellation());
        $this->deliver(['sessionId' => 'b', 'method' => 'Page.loadEventFired', 'params' => ['value' => 1]]);
        $this->deliver(['sessionId' => 'a', 'method' => 'Page.loadEventFired', 'params' => ['value' => 2]]);
        self::assertSame(['value' => 2], $event->await());
    }

    public function testCancellationDiscardsLateResponseWithoutClosingConnection(): void
    {
        $cancel = new DeferredCancellation();
        $command = $this->connection->send('Runtime.evaluate', cancellation: $cancel->getCancellation());
        delay(0);
        $cancel->cancel();
        try { $command->await(); self::fail('Expected cancellation'); }
        catch (CancelledException) {}
        $this->deliver(['id' => 1, 'result' => []]);
        self::assertFalse($this->connection->isClosed());
    }

    public function testProtocolErrorPreservesMethodMessageAndCode(): void
    {
        $command = $this->connection->send('Page.navigate');
        delay(0);
        $this->deliver(['id' => 1, 'error' => ['code' => -32602, 'message' => 'Invalid url']]);
        try { $command->await(); self::fail('Expected protocol error'); }
        catch (ProtocolException $error) {
            self::assertSame(-32602, $error->getCode());
            self::assertSame('Protocol error (Page.navigate): Invalid url', $error->getMessage());
        }
        self::assertFalse($this->connection->isClosed());
    }

    public function testDetachRejectsPendingCommandAndEventWaiter(): void
    {
        $session = $this->connection->session('page');
        $command = $session->send('Page.navigate');
        $event = $session->waitFor('Page.loadEventFired');
        delay(0);
        $this->deliver(['method' => 'Target.detachedFromTarget', 'params' => ['sessionId' => 'page']]);
        foreach ([$command, $event, $session->send('Page.enable')] as $future) {
            try { $future->await(); self::fail('Expected closed session'); }
            catch (TargetClosedException) {}
        }
        self::assertTrue($session->isClosed());
        self::assertFalse($this->connection->isClosed());
    }

    public function testCloseRejectsAllPendingAndReleasesTransportOnce(): void
    {
        $command = $this->connection->send('Browser.getVersion');
        $event = $this->connection->session()->waitFor('Target.targetCreated');
        delay(0);
        $this->connection->close();
        $this->connection->close();
        foreach ([$command, $event] as $future) {
            try { $future->await(); self::fail('Expected closed connection'); }
            catch (TargetClosedException) {}
        }
        self::assertSame(1, $this->closes);
        self::assertFalse(isset($this->transport->onmessage));
        self::assertFalse(isset($this->transport->onclose));
    }

    public function testCommandTimeoutCleansPendingRequests(): void
    {
        // The fake transport has no socket watcher to keep the event loop alive.
        $watcher = \Revolt\EventLoop::delay(1, static function (): void {});
        try { $this->connection->send('Browser.getVersion')->await(); self::fail('Expected timeout'); }
        catch (CancelledException) {}
        finally { \Revolt\EventLoop::cancel($watcher); }
        $pending = new \ReflectionProperty(Connection::class, 'pending');
        self::assertSame([], $pending->getValue($this->connection));
        self::assertFalse($this->connection->isClosed());
    }

    public function testMismatchedSessionTerminatesConnection(): void
    {
        $command = $this->connection->session('a')->send('Runtime.enable');
        delay(0);
        $this->deliver(['id' => 1, 'sessionId' => 'b', 'result' => []]);
        self::assertTrue($this->connection->isClosed());
        $this->expectException(\UnexpectedValueException::class);
        $command->await();
    }
    public function testCancelledQueuedCommandIsNotWritten(): void
    {
        $cancel = new DeferredCancellation();
        $cancel->cancel();
        try {
            $this->connection->send('Page.navigate', cancellation: $cancel->getCancellation())->await();
            self::fail('Expected cancellation');
        } catch (CancelledException) {}
        delay(0);
        self::assertSame([], $this->sent);
        self::assertFalse($this->connection->isClosed());
    }

    public function testWriteFailureClosesConnectionAndRejectsOtherCommands(): void
    {
        $broken = new Connection(new ConnectionTransport(
            static function (string $message): void { throw new \RuntimeException('Broken pipe'); },
            static function (): void {},
        ));
        $first = $broken->send('Page.enable');
        $second = $broken->send('Runtime.enable');
        foreach ([$first, $second] as $future) {
            try { $future->await(); self::fail('Expected write failure'); }
            catch (\RuntimeException) {}
        }
        self::assertTrue($broken->isClosed());
    }

    public function testDetachDropsSessionAndLateEventsDoNotRecreateIt(): void
    {
        $session = $this->connection->session('page');
        $this->deliver(['method' => 'Target.detachedFromTarget', 'params' => ['sessionId' => 'page']]);
        $this->deliver(['sessionId' => 'page', 'method' => 'Page.loadEventFired']);
        $sessions = (new \ReflectionProperty(Connection::class, 'sessions'))->getValue($this->connection);
        self::assertArrayNotHasKey('page', $sessions);
        self::assertTrue($session->isClosed());
    }

    public function testFaultyCloseObserverDoesNotPreventRemainingSessionCleanup(): void
    {
        $first = $this->connection->session('a');
        $second = $this->connection->session('b');
        $first->observe('__session_closed', static function (): void { throw new \LogicException('Observer failure'); });
        $event = $second->waitFor('Page.loadEventFired');
        try { $this->connection->close(); self::fail('Expected observer failure'); }
        catch (\LogicException $error) { self::assertSame('Observer failure', $error->getMessage()); }
        self::assertTrue($second->isClosed());
        self::assertSame(1, $this->closes);
        $this->expectException(TargetClosedException::class);
        $event->await();
    }

    public function testOpenRejectsInvalidTimeoutBeforeAttemptingConnection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Protocol timeout');
        Connection::open('not-a-websocket-url', -1);
    }

    public function testSlowMoDelaysResponsesWithoutBlockingTheirDelivery(): void
    {
        $this->connection->setSlowMo(0.02);
        $command = $this->connection->send('Browser.getVersion');
        delay(0);
        $this->deliver(['id' => 1, 'result' => ['product' => 'Chrome']]);
        self::assertFalse($command->isComplete());
        self::assertSame(['product' => 'Chrome'], $command->await());
    }

}
