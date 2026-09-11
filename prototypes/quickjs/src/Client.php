<?php
declare(strict_types=1);
namespace PuphpeteerQuickJs;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Websocket\Client\WebsocketConnection;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Websocket\Client\connect;

/** Experimental generic PHP facade over upstream Puppeteer in QuickJS. */
final class Client
{
    private \QuickJS $js;
    private \Js\Callback $dispatch;
    private ?WebsocketConnection $socket = null;
    private array $outbox = [];
    private array $writes = [];
    private bool $writing = false;
    private bool $pumpQueued = false;
    private bool $closed = false;
    private int $sequence = 0;
    /** @var array<int, DeferredFuture> */
    private array $pending = [];
    private array $callbacks = [];
    private array $timers = [];
    private array $objects = [];

    public function __construct(?string $bundle = null)
    {
        if (!method_exists(\QuickJS::class, 'runJobs')) {
            throw new \RuntimeException('Load the php-quickjs async-jobs fork; v0.0.2 has no Promise job pump.');
        }
        $this->js = new \QuickJS(memoryLimit: 256 * 1024 * 1024, timeoutMs: 2000, maxStack: 512 * 1024);
        $this->js->register('emit', function (string $kind, string $payload): void {
            // Never perform I/O or suspend a Fiber inside the extension.
            $this->outbox[] = [$kind, $payload];
        });
        $this->js->register('now', static fn(): float => hrtime(true) / 1e6);
        $source = file_get_contents($bundle ?? dirname(__DIR__) . '/build/puppeteer.js');
        if ($source === false) { throw new \RuntimeException('Build the Puppeteer bundle first.'); }
        $this->js->eval($source);
        $this->dispatch = $this->js->eval('globalThis.__quickjsDispatch');
    }

    public function connect(string $endpoint): Future
    {
        return async(function () use ($endpoint): RemoteObject {
            $this->socket = connect($endpoint);
            async(function (): void {
                try {
                    while (!$this->closed && ($message = $this->socket->receive())) {
                        $this->deliver('message', $message->buffer());
                    }
                    if (!$this->closed) { $this->stop(new \RuntimeException('Browser transport closed')); }
                } catch (\Throwable $e) { $this->stop($e); }
            })->ignore();
            return $this->call(0, 'connect', [])->await();
        });
    }

    public function call(int $object, string $method, array $arguments): Future
    {
        if ($this->closed) { return Future::error(new \RuntimeException('QuickJS client is closed')); }
        $id = ++$this->sequence;
        $deferred = $this->pending[$id] = new DeferredFuture();
        try {
            $this->deliver('call', $this->json(['id' => $id, 'object' => $object, 'method' => $method, 'args' => $this->encode($arguments)]));
        } catch (\Throwable $e) { $this->stop($e); }
        return $deferred->getFuture();
    }

    private function deliver(string $kind, string $payload): void
    {
        if ($this->closed) { return; }
        if (getenv('QUICKJS_TRACE') && $kind === 'message') { file_put_contents(getenv('QUICKJS_TRACE'), '< ' . $payload . "\n", FILE_APPEND); }
        ($this->dispatch)($kind, $payload);
        $this->pump();
    }

    private function pump(): void
    {
        if ($this->closed) { return; }
        $this->js->runJobs(100);
        $messages = $this->outbox;
        $this->outbox = [];
        $transportClosed = false;
        foreach ($messages as [$kind, $payload]) {
            if ($kind === 'send') { $this->writes[] = $payload; continue; }
            if ($kind === 'log') { fwrite(STDERR, "[QuickJS] $payload\n"); continue; }
            if ($kind === 'clearTimer') {
                $id = (int) $payload;
                if (isset($this->timers[$id])) { EventLoop::cancel($this->timers[$id]); unset($this->timers[$id]); }
                continue;
            }
            if ($kind === 'close') { $transportClosed = true; continue; }
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if ($kind === 'timer') {
                $id = $data['id'];
                $this->timers[$id] = EventLoop::delay($data['milliseconds'] / 1000, function () use ($id): void {
                    unset($this->timers[$id]);
                    try { $this->deliver('timer', (string) $id); } catch (\Throwable $e) { $this->stop($e); }
                });
            } elseif ($kind === 'result') {
                $future = $this->pending[$data['id']] ?? null;
                unset($this->pending[$data['id']]);
                if (!$future) { continue; }
                if (isset($data['error'])) { $future->error($this->guestError($data['error'])); }
                else { $future->complete($this->decode($data['value'])); }
            } elseif ($kind === 'callback') {
                async(function () use ($data): void {
                    try {
                        $fn = $this->callbacks[$data['callback']] ?? throw new \RuntimeException('Unknown PHP callback');
                        $value = $fn(...$this->decode($data['args']));
                        if ($value instanceof Future) { $value = $value->await(); }
                        $result = ['id' => $data['id'], 'value' => $this->encode($value)];
                    } catch (\Throwable $e) {
                        $result = ['id' => $data['id'], 'error' => ['name' => $e::class, 'message' => $e->getMessage()]];
                    }
                    try { $this->deliver('callbackResult', $this->json($result)); } catch (\Throwable $e) { $this->stop($e); }
                })->ignore();
            } elseif ($kind === 'fatal') { throw $this->guestError($data); }
        }
        if ($transportClosed) { $this->stop(); return; }
        $this->write();
        if ($this->js->hasPendingJobs() && !$this->pumpQueued) {
            $this->pumpQueued = true;
            EventLoop::defer(function (): void {
                $this->pumpQueued = false;
                try { $this->pump(); } catch (\Throwable $e) { $this->stop($e); }
            });
        }
    }

    private function write(): void
    {
        if ($this->writing || !$this->writes || !$this->socket) { return; }
        $this->writing = true;
        async(function (): void {
            try {
                while (!$this->closed && $this->writes) {
                    $message = array_shift($this->writes);
                    if (getenv('QUICKJS_TRACE')) { file_put_contents(getenv('QUICKJS_TRACE'), '> ' . $message . "\n", FILE_APPEND); }
                    $this->socket->sendText($message);
                }
            } catch (\Throwable $e) { $this->stop($e); }
            finally { $this->writing = false; }
        })->ignore();
    }

    private function encode(mixed $value): mixed
    {
        if ($value instanceof RemoteObject) { return ['$quickjs' => 'object', 'id' => $value->id]; }
        if ($value instanceof JavaScriptFunction) { return ['$quickjs' => 'function', 'source' => $value->source]; }
        if ($value instanceof \Closure) {
            $id = count($this->callbacks) + 1;
            $this->callbacks[$id] = $value;
            return ['$quickjs' => 'callback', 'id' => $id];
        }
        if (is_array($value)) { return array_map($this->encode(...), $value); }
        return $value;
    }

    private function decode(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (($value['$quickjs'] ?? null) === 'object') {
            $id = $value['id'];
            $object = ($this->objects[$id] ?? null)?->get();
            if (!$object) {
                $object = new RemoteObject($this, $id, $value['class']);
                $this->objects[$id] = \WeakReference::create($object);
            }
            return $object;
        }
        if (($value['$quickjs'] ?? null) === 'bytes') { return pack('C*', ...$value['value']); }
        if (isset($value['$quickjs'])) { return $value; } // Explicit tagged special values in this prototype.
        return array_map($this->decode(...), $value);
    }

    public function release(int $id): void
    {
        unset($this->objects[$id]);
        if (!$this->closed) { $this->deliver('release', $this->json(['id' => $id])); }
    }
    public function close(): void { $this->stop(); }
    private function stop(?\Throwable $error = null): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        foreach ($this->timers as $watcher) { EventLoop::cancel($watcher); }
        $this->timers = [];
        $this->writes = $this->outbox = [];
        $this->callbacks = [];
        foreach ($this->pending as $future) { $future->error($error ?? new \RuntimeException('QuickJS client closed')); }
        $this->pending = [];
        $this->socket?->close();
    }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private function guestError(array $error): \RuntimeException
    {
        return new \RuntimeException(($error['name'] ?? 'Error') . ': ' . ($error['message'] ?? '') . "\n" . ($error['stack'] ?? ''));
    }
}
