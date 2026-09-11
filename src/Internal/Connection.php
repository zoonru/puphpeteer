<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\LocalMutex;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Nesk\Puphpeteer\ConnectionTransport;
use function Amp\async;
use function Amp\delay;

/** A single reader routes commands and events across flattened CDP sessions.
 * @internal
 */
final class Connection
{
    private int $nextId = 0;
    /** @var array<int,array{future:DeferredFuture<array<string,mixed>>,session:?string,method:string}> */
    private array $pending = [];
    /** @var array<string,Session> */
    private array $sessions = [];
    private LocalMutex $writer;
    private bool $closed = false;
    private float $slowMo = 0;

    public function __construct(private ConnectionTransport $transport, private float $timeout = 180)
    {
        if ($timeout < 0 || !is_finite($timeout)) {
            throw new \InvalidArgumentException('Protocol timeout must be finite and non-negative');
        }
        $this->writer = new LocalMutex();
        $transport->onmessage = function (string $message): void {
            $receive = function () use ($message): void {
                try {
                    if ($this->slowMo > 0) { delay($this->slowMo); }
                    $this->receive($message);
                } catch (\Throwable $error) { $this->shutdown($error); }
            };
            if ($this->slowMo > 0) { async($receive)->ignore(); }
            else { $receive(); }
        };
        $transport->onclose = fn () => $this->shutdown(new TargetClosedException('Browser connection closed'));
    }

    /** @param array<string,string> $headers */
    public static function open(string $endpoint, float $timeout = 180, array $headers = [], ?Cancellation $cancellation = null): self
    {
        if ($timeout < 0 || !is_finite($timeout)) {
            throw new \InvalidArgumentException('Protocol timeout must be finite and non-negative');
        }
        $cancel = $timeout > 0 ? new TimeoutCancellation($timeout) : null;
        if ($cancellation !== null) { $cancel = $cancel ? new CompositeCancellation($cancel, $cancellation) : $cancellation; }
        $handshake = new WebsocketHandshake($endpoint);
        foreach ($headers as $name => $value) {
            if ($name === '') { throw new \InvalidArgumentException('Header name cannot be empty'); }
            $handshake = $handshake->withHeader($name, $value);
        }
        // Match Puppeteer's Node transport, including its 256 MiB payload limit.
        $connector = new Rfc6455Connector(
            connectionFactory: new Rfc6455ConnectionFactory(parserFactory: new Rfc6455ParserFactory(
                messageSizeLimit: 256 * 1024 * 1024,
                frameSizeLimit: 256 * 1024 * 1024,
            )),
            compressionContextFactory: null,
        );
        return new self(new WebSocketTransport($connector->connect($handshake, $cancel)), $timeout);
    }

    public function isClosed(): bool { return $this->closed; }

    public function setSlowMo(float $seconds): void
    {
        if ($seconds < 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('slowMo must be finite and non-negative');
        }
        $this->slowMo = $seconds;
    }

    public function session(?string $id = null): Session
    {
        $session = $this->sessions[$id ?? ''] ??= new Session($this, $id);
        if ($this->closed) { $session->markClosed(); }
        return $session;
    }

    /** @param array<string,mixed> $params @return Future<array<string,mixed>> */
    public function send(string $method, array $params = [], ?string $sessionId = null, ?Cancellation $cancellation = null): Future
    {
        if ($this->closed) { return Future::error(new TargetClosedException('Browser connection is closed')); }
        $id = ++$this->nextId;
        $payload = ['id' => $id, 'method' => $method, 'params' => (object) $params];
        if ($sessionId !== null) { $payload['sessionId'] = $sessionId; }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        return async(function () use ($id, $method, $sessionId, $encoded, $cancellation): array {
            if ($this->closed) { throw new TargetClosedException('Browser connection is closed'); }
            $cancel = $this->timeout > 0 ? new TimeoutCancellation($this->timeout) : null;
            if ($cancellation !== null) { $cancel = $cancel ? new CompositeCancellation($cancel, $cancellation) : $cancellation; }
            /** @var DeferredFuture<array<string,mixed>> $deferred */
            $deferred = new DeferredFuture();
            $deferred->getFuture()->ignore();
            $this->pending[$id] = ['future' => $deferred, 'session' => $sessionId, 'method' => $method];
            try {
                // A frame in progress finishes atomically; a canceled queued frame is never sent.
                $write = async(function () use ($encoded, $cancel): void {
                    $lock = $this->writer->acquire();
                    try {
                        $cancel?->throwIfRequested();
                        if ($this->closed) { throw new TargetClosedException('Browser connection is closed'); }
                        $this->transport->send($encoded);
                    } catch (\Throwable $error) {
                        if (!$cancel?->isRequested()) { $this->shutdown($error); }
                        throw $error;
                    } finally { $lock->release(); }
                });
                $write->ignore();
                $write->await($cancel);
                return $deferred->getFuture()->await($cancel);
            } finally { unset($this->pending[$id]); }
        });
    }

    private function receive(string $message): void
    {
        if ($this->closed) { return; }
        $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException('Invalid CDP message'); }
        if (isset($data['id'])) {
            if (!is_int($data['id'])) { throw new \UnexpectedValueException('Invalid CDP request id'); }
            $entry = $this->pending[$data['id']] ?? null;
            if ($entry === null) { return; } // Responses to canceled requests are harmless.
            if (($data['sessionId'] ?? null) !== $entry['session']) {
                throw new \UnexpectedValueException('CDP response has an unexpected sessionId');
            }
            unset($this->pending[$data['id']]);
            if (isset($data['error'])) {
                $error = $data['error'];
                $entry['future']->error(new ProtocolException($entry['method'], is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'Unknown error', is_array($error) && is_int($error['code'] ?? null) ? $error['code'] : 0));
            } else {
                $result = $data['result'] ?? [];
                if (!is_array($result)) {
                    $entry['future']->error(new \UnexpectedValueException('Invalid CDP result'));
                } else {
                    /** @var array<string,mixed> $result */
                    $entry['future']->complete($result);
                }
            }
            return;
        }
        $method = $data['method'] ?? null;
        $params = $data['params'] ?? [];
        $sessionId = $data['sessionId'] ?? null;
        if (!is_string($method) || !is_array($params) || ($sessionId !== null && !is_string($sessionId))) {
            throw new \UnexpectedValueException('Invalid CDP event');
        }
        if ($method === 'Target.detachedFromTarget' && is_string($params['sessionId'] ?? null)) {
            $this->detach($params['sessionId']);
        }
        /** @var array<string,mixed> $params */
        $session = $sessionId === null ? $this->session() : ($this->sessions[$sessionId] ?? null);
        $session?->dispatch($method, $params);
    }

    private function detach(string $id): void
    {
        foreach ($this->pending as $key => $entry) {
            if ($entry['session'] === $id) {
                unset($this->pending[$key]);
                $entry['future']->error(new TargetClosedException('CDP session detached'));
            }
        }
        if (isset($this->sessions[$id])) {
            $session = $this->sessions[$id];
            unset($this->sessions[$id]);
            $session->markClosed();
        }
    }

    private function shutdown(\Throwable $error): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        foreach ($this->pending as $entry) { $entry['future']->error($error); }
        $this->pending = [];
        $observerError = null;
        try {
            foreach ($this->sessions as $session) {
                try { $session->markClosed(); }
                catch (\Throwable $failure) { $observerError ??= $failure; }
            }
        } finally {
            $this->sessions = [];
            unset($this->transport->onmessage, $this->transport->onclose);
            $this->transport->close();
        }
        if ($observerError !== null) { throw $observerError; }
    }

    public function close(): void { $this->shutdown(new TargetClosedException('Browser connection closed by PHP')); }
}
