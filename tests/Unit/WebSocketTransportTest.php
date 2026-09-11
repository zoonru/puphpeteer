<?php

declare(strict_types=1);

namespace Tests\Unit;

use Amp\DeferredFuture;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use Nesk\Puphpeteer\Internal\WebSocketTransport;
use PHPUnit\Framework\TestCase;
use function Amp\async;

final class WebSocketTransportTest extends TestCase
{
    public function testBufferedMessagesAllowPendingFutureContinuationsToRunInBetween(): void
    {
        $socket = $this->createStub(WebsocketConnection::class);
        $socket->method('receive')->willReturnOnConsecutiveCalls(
            WebsocketMessage::fromText('first'),
            WebsocketMessage::fromText('second'),
            null,
        );
        $event = new DeferredFuture();
        $closed = new DeferredFuture();
        $order = [];
        $continuation = async(function () use ($event, &$order): void {
            $event->getFuture()->await();
            // Promise chains may queue further continuations before the next message turn.
            async(static function (): void {})->await();
            $order[] = 'continued';
        });
        $transport = new WebSocketTransport($socket);
        $transport->onmessage = static function (string $payload) use ($event, &$order): void {
            $order[] = $payload;
            if ($payload === 'first') { $event->complete(); }
        };
        $transport->onclose = static function () use ($closed): void { $closed->complete(); };
        $closed->getFuture()->await();
        $continuation->await();
        self::assertSame(['first', 'continued', 'second'], $order);
    }
}
