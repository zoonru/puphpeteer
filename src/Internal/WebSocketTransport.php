<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Websocket\Client\WebsocketConnection;
use Nesk\Puphpeteer\ConnectionTransport;
use Revolt\EventLoop;
use function Amp\async;

/** @internal */
final class WebSocketTransport extends ConnectionTransport
{
    public function __construct(WebsocketConnection $socket)
    {
        parent::__construct($socket->sendText(...), $socket->close(...));
        async(function () use ($socket): void {
            try {
                while (($message = $socket->receive()) !== null) {
                    $payload = $message->buffer();
                    if (isset($this->onmessage)) { ($this->onmessage)($payload); }
                    // Buffered frames must not overtake Futures resolved by this message.
                    // A deferred turn drains the complete continuation queue, like JS microtasks.
                    $suspension = EventLoop::getSuspension();
                    EventLoop::defer(static fn () => $suspension->resume());
                    $suspension->suspend();
                }
            } finally {
                if (isset($this->onclose)) { ($this->onclose)(); }
            }
        })->ignore();
    }
}
