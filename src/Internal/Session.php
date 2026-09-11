<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use function Amp\async;

/** @internal */
final class Session
{
    /** @var array<string, array<int, callable(array<string, mixed>):void>> */
    private array $observers = [];
    /** @var array<string, array<int, array{?callable(array<string,mixed>):bool, DeferredFuture<array<string,mixed>>}>> */
    private array $waiters = [];
    private int $nextId = 0;
    private bool $closed = false;

    public function __construct(private Connection $connection, private ?string $id) {}

    public function id(): ?string { return $this->id; }
    public function isClosed(): bool { return $this->closed; }

    /** @param array<string,mixed> $params @return Future<array<string,mixed>> */
    public function send(string $method, array $params = [], ?Cancellation $cancellation = null): Future
    {
        return $this->closed ? Future::error(new TargetClosedException('CDP session is closed'))
            : $this->connection->send($method, $params, $this->id, $cancellation);
    }

    /** Observers run in receive order and must not suspend.
     * @param callable(array<string,mixed>):void $observer
     */
    public function observe(string $event, callable $observer): int
    {
        $id = ++$this->nextId;
        $this->observers[$event][$id] = $observer;
        return $id;
    }

    public function off(string $event, int $id): void
    {
        unset($this->observers[$event][$id]);
    }

    /** Registers immediately, before the command triggering the event is sent.
     * @param null|callable(array<string,mixed>):bool $predicate
     * @return Future<array<string,mixed>>
     */
    public function waitFor(string $event, ?callable $predicate = null, ?Cancellation $cancellation = null): Future
    {
        if ($this->closed) {
            return Future::error(new TargetClosedException('CDP session is closed'));
        }
        $id = ++$this->nextId;
        /** @var DeferredFuture<array<string,mixed>> $deferred */
        $deferred = new DeferredFuture();
        $deferred->getFuture()->ignore();
        $this->waiters[$event][$id] = [$predicate, $deferred];
        return async(function () use ($event, $id, $deferred, $cancellation): array {
            try {
                return $deferred->getFuture()->await($cancellation);
            } finally {
                unset($this->waiters[$event][$id]);
            }
        });
    }

    /** @param array<string,mixed> $params */
    public function dispatch(string $event, array $params): void
    {
        foreach ($this->observers[$event] ?? [] as $observer) {
            $observer($params);
        }
        foreach ($this->waiters[$event] ?? [] as $id => [$predicate, $deferred]) {
            try {
                if ($predicate === null || $predicate($params)) {
                    unset($this->waiters[$event][$id]);
                    $deferred->complete($params);
                }
            } catch (\Throwable $error) {
                unset($this->waiters[$event][$id]);
                $deferred->error($error);
            }
        }
    }

    public function markClosed(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        foreach ($this->waiters as $waiters) {
            foreach ($waiters as [, $deferred]) {
                $deferred->error(new TargetClosedException('CDP session closed while waiting for event'));
            }
        }
        $this->waiters = [];
        $observers = $this->observers['__session_closed'] ?? [];
        $this->observers = [];
        $failure = null;
        foreach ($observers as $observer) {
            try { $observer([]); }
            catch (\Throwable $error) { $failure ??= $error; }
        }
        if ($failure !== null) { throw $failure; }
    }
}
