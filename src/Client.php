<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;

use Amp\DeferredFuture;
use Amp\Future;
use Nesk\Puphpeteer\Internal\RemoteObjectFactory;
use Amp\Websocket\Client\WebsocketConnection;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Websocket\Client\connect;

/** @internal Async transport over upstream Puppeteer in QuickJS. Use Puppeteer as the public entry point. */
final class Client
{
    private \QuickJS $js;
    private \Js\Callback $dispatch;
    private ?WebsocketConnection $socket = null;
    private array $writes = [];
    private bool $writing = false;
    private bool $pumpQueued = false;
    private bool $closed = false;
    private string $endpoint = '';
    private int $sequence = 0;
    /** @var array<int, DeferredFuture<mixed>> */
    private array $pending = [];
    private array $callbacks = [];
    /** @var null|\WeakMap<\Closure|JsFunction, int> */
    private ?\WeakMap $functionIds = null;
    private int $functionSequence = 0;
    private ?Internal\BrowserProcess $browserProcess = null;
    private array $timers = [];
    private array $objects = [];

    public function __construct(?string $bundle = null)
    {
        if (!method_exists(\Js\Callback::class, 'dispatch')) {
            throw new \RuntimeException('Load the php-quickjs fork with Js\\Callback::dispatch().');
        }
        $this->js = new \QuickJS(memoryLimit: 256 * 1024 * 1024, timeoutMs: 2000, maxStack: 512 * 1024);
        $this->js->register('now', static fn(): float => (float) hrtime(true) / 1e6);
        $source = file_get_contents($bundle ?? dirname(__DIR__) . '/resources/puppeteer.js');
        if ($source === false) { throw new \RuntimeException('Build the Puppeteer bundle first.'); }
        $this->js->eval($source);
        $this->dispatch = $this->js->eval('globalThis.__quickjsDispatch');
    }

    /** @return Future<Browser> */
    public function connect(string $endpoint, array $options = []): Future
    {
        return async(function () use ($endpoint, $options): Browser {
            $this->endpoint = $endpoint;
            $socket = $this->socket = connect($endpoint);
            async(function () use ($socket): void {
                try {
                    while (!$this->closed && ($message = $socket->receive())) {
                        $this->deliver('message', $message->buffer());
                    }
                    if (!$this->closed) { $this->stop(new \RuntimeException('Browser transport closed')); }
                } catch (\Throwable $e) { $this->stop($e); }
            })->ignore();
            $browser = $this->call(0, 'connect', [$options])->await();
            if (!$browser instanceof Browser) {
                throw new \UnexpectedValueException('Puppeteer connect did not return a Browser');
            }
            return $browser;
        });
    }

    /** @return Future<mixed> */
    public function call(int $object, string $method, array $arguments, string $operation = 'call'): Future
    {
        if ($this->closed) { return Future::error(new \RuntimeException('QuickJS client is closed')); }
        $id = ++$this->sequence;
        $deferred = $this->pending[$id] = new DeferredFuture();
        try {
            $this->deliver('call', ['id' => $id, 'object' => $object, 'method' => $method, 'operation' => $operation, 'args' => $this->encode($arguments)]);
        } catch (\Throwable $e) { $this->stop($e); }
        return $deferred->getFuture();
    }

    private function deliver(string $kind, mixed $payload): void
    {
        if ($this->closed) { return; }
        $trace = getenv('QUICKJS_TRACE');
        if ($trace !== false && $trace !== '' && $trace !== '0' && $kind === 'message') { file_put_contents($trace, '< ' . $payload . "\n", FILE_APPEND); }
        $this->pump([$kind, $payload]);
    }

    /** @param list<mixed>|null $arguments */
    private function pump(?array $arguments = null): void
    {
        if ($this->closed) { return; }
        $batch = $this->dispatch->dispatch($arguments, 100);
        $messages = $batch['messages'];
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
            $data = $payload;
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
                    try { $this->deliver('callbackResult', $result); } catch (\Throwable $e) { $this->stop($e); }
                })->ignore();
            } elseif ($kind === 'fatal') { throw $this->guestError($data); }
        }
        if ($transportClosed) { $this->stop(); return; }
        $this->write();
        if ($batch['pending'] && !$this->pumpQueued) {
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
        $socket = $this->socket;
        $this->writing = true;
        async(function () use ($socket): void {
            try {
                while (!$this->closed && $this->writes) {
                    $message = array_shift($this->writes);
                    $trace = getenv('QUICKJS_TRACE');
                    if ($trace !== false && $trace !== '' && $trace !== '0') { file_put_contents($trace, '> ' . $message . "\n", FILE_APPEND); }
                    $socket->sendText($message);
                }
            } catch (\Throwable $e) { $this->stop($e); }
            finally { $this->writing = false; }
        })->ignore();
    }

    private function encode(mixed $value): mixed
    {
        if ($value instanceof RemoteObject) { return ['$quickjs' => 'object', 'id' => $value->remoteId()]; }
        if ($value instanceof JsFunction || $value instanceof \Closure) {
            if ($this->functionIds === null) {
                /** @var \WeakMap<\Closure|JsFunction, int> $ids */
                $ids = new \WeakMap();
                $this->functionIds = $ids;
            }
            $id = $this->functionIds[$value] ?? null;
            if ($id === null) { $id = ++$this->functionSequence; $this->functionIds[$value] = $id; }
            if ($value instanceof JsFunction) { return ['$quickjs' => 'function', 'id' => $id, 'source' => $value->source]; }
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
            if ($object === null) {
                $object = RemoteObjectFactory::create($this, $id, $value['class']);
                $this->objects[$id] = \WeakReference::create($object);
            }
            return $object;
        }
        if (($value['$quickjs'] ?? null) === 'undefined') { return null; }
        if (($value['$quickjs'] ?? null) === 'bytes') { return $value['value']; }
        if (isset($value['$quickjs'])) { return $value; } // Explicit tagged special values in this prototype.
        return array_map($this->decode(...), $value);
    }

    public function release(int $id): void
    {
        unset($this->objects[$id]);
        if (!$this->closed) { $this->deliver('release', ['id' => $id]); }
    }
    /** @internal
     * @psalm-mutation-free
     */
    public function endpoint(): string { return $this->endpoint; }
    /** @internal
     * @psalm-external-mutation-free
     */
    public function ownBrowser(Internal\BrowserProcess $process): void { $this->browserProcess = $process; }
    /** @internal */
    public function browserClosed(): void
    {
        $this->browserProcess?->close();
        $this->browserProcess = null;
        $this->stop();
    }
    public function close(): void { $this->stop(); }
    private function stop(?\Throwable $error = null): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        foreach ($this->timers as $watcher) { EventLoop::cancel($watcher); }
        $this->timers = [];
        $this->writes = [];
        $this->callbacks = [];
        foreach ($this->pending as $future) { $future->error($error ?? new \RuntimeException('QuickJS client closed')); }
        $this->pending = [];
        $this->socket?->close();
    }
    /** @psalm-pure */
    private function guestError(array $error): \RuntimeException
    {
        return new \RuntimeException(($error['name'] ?? 'Error') . ': ' . ($error['message'] ?? '') . "\n" . ($error['stack'] ?? ''));
    }
}
