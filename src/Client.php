<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;

use Nesk\Puphpeteer\Puppeteer\Browser;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Amp\Future;
use Nesk\Puphpeteer\Internal\RemoteObjectFactory;
use Amp\Websocket\Client\WebsocketConnection;
use Revolt\EventLoop;
use function Amp\async;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;

/** @internal Async transport over upstream Puppeteer in QuickJS. Use Puppeteer as the public entry point. */
final class Client
{
    private \QuickJS $js;
    private \Js\Callback $dispatch;
    private ?WebsocketConnection $socket = null;
    private array $writes = [];
    /** @var array<int, Future<void>> */
    private array $logs = [];
    private int $logSequence = 0;
    private int $logBytes = 0;
    private int $droppedLogs = 0;
    private bool $writing = false;
    private ?string $pumpWatcher = null;
    private bool $connecting = false;
    private bool $closed = false;
    private string $endpoint = '';
    private ?float $protocolTimeout = null;
    private int $sequence = 0;
    /** @var array<int, DeferredFuture<mixed>> */
    private array $pending = [];
    private array $callbacks = [];
    /** @var null|\WeakMap<\Closure|JsFunction, int> */
    private ?\WeakMap $functionIds = null;
    /** @var null|\WeakMap<JsFunction, Internal\FunctionReference> */
    private ?\WeakMap $functionReferences = null;
    private int $functionSequence = 0;
    private ?Internal\BrowserProcess $browserProcess = null;
    private array $timers = [];
    private array $objects = [];
    /** @var array<int, \WeakReference<Internal\ReadableStream>> */
    private array $streams = [];
    private ?Internal\HostFilesystem $filesystem = null;

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
    public function connect(string $endpoint, array $options = [], ?Cancellation $cancellation = null): Future
    {
        if ($this->closed || $this->connecting || $this->socket !== null) {
            return Future::error(new \LogicException('Client can only connect once'));
        }
        $this->connecting = true;
        return async(function () use ($endpoint, $options, $cancellation): Browser {
            $this->endpoint = $endpoint;
            $timeout = $options['protocolTimeout'] ?? 180000;
            $this->protocolTimeout = $timeout > 0 ? (float) $timeout : null;
            $cancellation ??= $timeout > 0 ? new TimeoutCancellation($timeout / 1000) : null;
            try {
                // Match Puppeteer's Node transport without changing the application's global connector.
                $connector = new Rfc6455Connector(
                    connectionFactory: new Rfc6455ConnectionFactory(parserFactory: new Rfc6455ParserFactory(
                        messageSizeLimit: 256 * 1024 * 1024,
                        frameSizeLimit: 256 * 1024 * 1024,
                    )),
                    compressionContextFactory: null,
                );
                $socket = $connector->connect(new WebsocketHandshake($endpoint), $cancellation);
            }
            catch (\Throwable $error) { $this->stop($error); throw $error; }
            if ($this->closed) { $socket->close(); throw new \RuntimeException('QuickJS client closed while connecting'); }
            $this->socket = $socket;
            async(function () use ($socket): void {
                try {
                    while (!$this->closed && ($message = $socket->receive())) {
                        $this->deliverMessage($message->buffer());
                    }
                    if (!$this->closed) { $this->stop(new \RuntimeException('Browser transport closed')); }
                } catch (\Throwable $e) { $this->stop($e); }
            })->ignore();
            try {
                $browser = $this->call(0, 'connect', [$options], cancellation: $cancellation)->await();
            } catch (\Throwable $error) {
                $this->stop($error);
                throw $error;
            }
            if (!$browser instanceof Browser) {
                throw new \UnexpectedValueException('Puppeteer connect did not return a Browser');
            }
            return $browser;
        });
    }

    /** @return Future<mixed> */
    public function call(int $object, string $method, array $arguments, string $operation = 'call', ?Cancellation $cancellation = null): Future
    {
        if ($this->closed) { return Future::error(new \RuntimeException('QuickJS client is closed')); }
        try { $cancellation?->throwIfRequested(); $encoded = $this->encode($arguments); }
        catch (\Throwable $error) { return Future::error($error); }
        $id = ++$this->sequence;
        $deferred = $this->pending[$id] = new DeferredFuture();
        try {
            $this->deliver('call', ['id' => $id, 'object' => $object, 'method' => $method, 'operation' => $operation, 'args' => $encoded]);
        } catch (\Throwable $e) { $this->stop($e); }
        $automaticTimeout = $cancellation === null && $this->protocolTimeout !== null;
        $cancellation ??= $automaticTimeout ? new TimeoutCancellation($this->protocolTimeout / 1000.0) : null;
        if ($cancellation === null) { return $deferred->getFuture(); }
        return async(function () use ($deferred, $cancellation, $id, $automaticTimeout, $operation): mixed {
            try { return $deferred->getFuture()->await($cancellation); }
            catch (\Amp\CancelledException $error) {
                if (($this->pending[$id] ?? null) === $deferred) { unset($this->pending[$id]); }
                if (!$automaticTimeout && $operation !== 'stream') { $this->stop($error); }
                throw $error;
            }
        });
    }

    private function deliverMessage(string $message): void
    {
        $length = strlen($message);
        if ($length <= 65536) { $this->deliver('message', $message); return; }
        // CDP JSON can exceed the native single-value budget even when its decoded result fits.
        for ($offset = 0; $offset < $length;) {
            $end = min($length, $offset + 65536);
            // Never split a UTF-8 sequence at the native string conversion boundary.
            while ($end < $length && (ord($message[$end]) & 0xc0) === 0x80) { $end--; }
            $this->deliver('messageChunk', substr($message, $offset, $end - $offset));
            $offset = $end;
            \Amp\delay(0);
            if ($this->closed) { return; }
        }
        $this->deliver('messageEnd', null);
    }

    private function deliver(string $kind, mixed $payload): void
    {
        if ($this->closed) { return; }
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
            if ($kind === 'releaseCallback') { unset($this->callbacks[(int) $payload]); continue; }
            if ($kind === 'log') {
                // A slow log consumer must not suspend processing the rest of this native batch.
                $line = '[QuickJS] ' . substr($payload, 0, 65536) . "\n";
                $bytes = strlen($line);
                // Diagnostics must not retain unlimited memory when stderr is stalled.
                if (count($this->logs) >= 64 || $this->logBytes + $bytes > 1048576) { ++$this->droppedLogs; continue; }
                $log = ++$this->logSequence;
                $this->logBytes += $bytes;
                $this->logs[$log] = async(function () use ($log, $line, $bytes): void {
                    try { \Amp\ByteStream\getStderr()->write($line); }
                    finally { unset($this->logs[$log]); $this->logBytes -= $bytes; }
                })->ignore();
                continue;
            }
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
                else {
                    try { $future->complete($this->decode($data['value'])); }
                    catch (\Throwable $error) { $future->error($error); }
                }
            } elseif ($kind === 'callback' || $kind === 'filesystem') {
                $fn = $kind === 'filesystem'
                    ? fn (...$arguments) => ($this->filesystem ??= new Internal\HostFilesystem())->call($data['operation'], $arguments)
                    : ($this->callbacks[$data['callback']] ?? null);
                async(function () use ($data, $fn): void {
                    if ($this->closed) { return; }
                    try {
                        if ($fn === null) { throw new \RuntimeException('Unknown PHP callback'); }
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
        if ($batch['pending'] && $this->pumpWatcher === null) {
            $this->pumpWatcher = EventLoop::defer(function (): void {
                $this->pumpWatcher = null;
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
                    $socket->sendText($message);
                }
            } catch (\Throwable $e) { $this->stop($e); }
            finally { $this->writing = false; }
        })->ignore();
    }

    private function encode(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) { throw new \InvalidArgumentException('Bridge arguments exceed maximum depth 64'); }
        if ($value instanceof RemoteObject) {
            if (!$value->belongsTo($this)) { throw new \InvalidArgumentException('Remote object belongs to another client or has been released'); }
            return ['$quickjs' => 'object', 'id' => $value->remoteId()];
        }
        if ($value instanceof JsFunction || $value instanceof \Closure) {
            if ($this->functionIds === null) {
                /** @var \WeakMap<\Closure|JsFunction, int> $ids */
                $ids = new \WeakMap();
                $this->functionIds = $ids;
            }
            $id = $this->functionIds[$value] ?? null;
            if ($id === null) { $id = ++$this->functionSequence; $this->functionIds[$value] = $id; }
            if ($value instanceof JsFunction) {
                if ($this->functionReferences === null) {
                    /** @var \WeakMap<JsFunction, Internal\FunctionReference> $references */
                    $references = new \WeakMap();
                    $this->functionReferences = $references;
                }
                if (!isset($this->functionReferences[$value])) { $this->functionReferences[$value] = new Internal\FunctionReference($this, $id); }
                return ['$quickjs' => 'function', 'id' => $id, 'source' => $value->source];
            }
            $this->callbacks[$id] = $value;
            return ['$quickjs' => 'callback', 'id' => $id];
        }
        if (is_array($value)) {
            $record = array_map(fn(mixed $item): mixed => $this->encode($item, $depth + 1), $value);
            return array_key_exists('$quickjs', $record) ? ['$quickjs' => 'record', 'value' => $record] : $record;
        }
        return $value;
    }

    private function decode(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        if (($value['$quickjs'] ?? null) === 'record') { return array_map($this->decode(...), $value['value']); }
        if (($value['$quickjs'] ?? null) === 'stream') {
            $id = $value['id'];
            $stream = ($this->streams[$id] ?? null)?->get();
            if ($stream === null) {
                $stream = new Internal\ReadableStream($this, $id);
                $this->streams[$id] = \WeakReference::create($stream);
            }
            return $stream;
        }
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

    /** @internal Schedule finalizer work outside native callbacks. */
    public function releaseFunctionLater(int $id): void
    {
        if ($this->closed) { return; }
        EventLoop::queue(function () use ($id): void {
            try { $this->deliver('releaseFunction', ['id' => $id]); }
            catch (\Throwable $error) { $this->stop($error); }
        });
    }
    /** @internal Schedule finalizer work outside native callbacks. */
    public function releaseLater(int $id): void
    {
        if ($this->closed) { return; }
        EventLoop::queue(function () use ($id): void {
            if (($this->objects[$id] ?? null)?->get() === null) { $this->release($id); }
        });
    }
    /**
     * @internal
     * @psalm-external-mutation-free
     */
    public function forgetStream(int $id): void { unset($this->streams[$id]); }

    /** @internal Stream finalizers must not re-enter QuickJS. */
    public function cancelStreamLater(int $id, bool $onlyIfUnreferenced = false): void
    {
        if ($this->closed) { return; }
        EventLoop::queue(function () use ($id, $onlyIfUnreferenced): void {
            if ($onlyIfUnreferenced && ($this->streams[$id] ?? null)?->get() !== null) { return; }
            $this->call($id, 'cancel', [], 'stream')->ignore();
        });
    }

    public function release(int $id): void
    {
        unset($this->objects[$id]);
        if (!$this->closed) {
            try { $this->deliver('release', ['id' => $id]); } catch (\Throwable $error) { $this->stop($error); }
        }
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
        try { $this->browserProcess?->close(); }
        finally { $this->browserProcess = null; $this->close(); }
    }
    /** @internal
     * @psalm-mutation-free
     */
    public function isClosed(): bool { return $this->closed; }
    public function close(): void
    {
        $this->stop();
        // Flush queued diagnostics on explicit close without letting a stuck reader prevent shutdown.
        if ($this->logs === []) { return; }
        try { \Amp\Future\awaitAll($this->logs, new TimeoutCancellation(1)); }
        catch (\Amp\CancelledException) {}
    }
    private function stop(?\Throwable $error = null): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        if ($this->pumpWatcher !== null) { EventLoop::cancel($this->pumpWatcher); $this->pumpWatcher = null; }
        // Drop JS transport callbacks, timers and object roots as well as PHP bookkeeping.
        try { $this->dispatch->dispatch(['closed', null], 100); } catch (\Throwable) {}
        foreach ($this->timers as $watcher) { EventLoop::cancel($watcher); }
        $this->timers = [];
        $this->writes = [];
        $this->filesystem?->closeAll();
        $this->callbacks = [];
        $this->functionIds = null;
        $this->functionReferences = null;
        $this->objects = [];
        foreach ($this->streams as $stream) { $stream->get()?->transportClosed($error); }
        $this->streams = [];
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
