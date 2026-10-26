<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\LocalSemaphore;
use Amp\TimeoutCancellation;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Closure;
use InvalidArgumentException;
use Js\Callback;
use LogicException;
use Nesk\Puphpeteer\Internal\NpmModuleHost;
use Nesk\Puphpeteer\Internal\RemoteObjectFactory;
use Nesk\Puphpeteer\Puppeteer\Browser;
use Psr\Log\LoggerInterface;
use QuickJS;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use WeakMap;
use WeakReference;

use function Amp\async;
use function Amp\File\read;

/** @internal Async transport over upstream Puppeteer in QuickJS. Use Puppeteer as the public entry point. */
final class Client
{
    private QuickJS $js;
    private Callback $dispatch;
    private LocalSemaphore $dispatchSlots;
    private int $dispatching = 0;
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $dispatchIdle = null;
    private bool $draining = false;
    private int $driveGeneration = 0;
    private ?WebsocketConnection $socket = null;
    private array $writes = [];
    private bool $writing = false;
    private bool $connecting = false;
    private bool $closed = false;
    private ?Throwable $closeCause = null;
    private string $endpoint = '';
    private ?float $protocolTimeout = null;
    private int $sequence = 0;
    /** @var array<int, DeferredFuture<mixed>> */
    private array $pending = [];
    /** @var array<int, int> */
    private array $objectLeases = [];
    /** @var array<int, int> */
    private array $streamLeases = [];
    private array $callbacks = [];
    /** @var WeakMap<Closure|JsFunction, int>|null */
    private ?WeakMap $functionIds = null;
    /** @var WeakMap<JsFunction, Internal\FunctionReference>|null */
    private ?WeakMap $functionReferences = null;
    private int $functionSequence = 0;
    private ?Internal\BrowserProcess $browserProcess = null;
    private array $timers = [];
    private array $objects = [];
    /** @var array<int, WeakReference<Internal\ReadableStream>> */
    private array $streams = [];
    private ?Internal\HostFilesystem $filesystem = null;
    private ?Internal\HostHttp $http = null;
    private LoggerInterface $logger;

    public function __construct(?string $bundle = null, ?LoggerInterface $logger = null, ?NpmModuleHost $moduleHost = null)
    {
        if (!method_exists(QuickJS::class, 'awaitMessages') || !method_exists(QuickJS::class, 'close') || !method_exists(Callback::class, 'post')) {
            throw new RuntimeException('Load php-quickjs with QuickJS::awaitMessages(), QuickJS::close() and Js\\Callback::post().');
        }
        $this->logger = $logger ?? new Internal\StderrLogger();
        $this->dispatchSlots = new LocalSemaphore(64);
        $this->js = new QuickJS(memoryLimit: 256 * 1024 * 1024, maxStack: 512 * 1024);
        $this->js->register('randomBytes', Internal\HostCrypto::randomBytes(...));
        if (null !== $moduleHost) {
            $this->js->register('npm.resolve', $moduleHost->resolve(...));
            $this->js->register('npm.root', $moduleHost->root(...));
            $this->js->register('npm.readSource', $moduleHost->readSource(...));
            $this->js->register('npm.readBinary', $moduleHost->readBinary(...));
            $this->js->register('npm.decodeUtf8', $moduleHost->decodeUtf8(...));
            $this->js->register('npm.crc32', $moduleHost->crc32(...));
            $this->js->register('npm.exists', $moduleHost->exists(...));
            $this->js->register('npm.inflateRaw', $moduleHost->inflateRaw(...));
            $this->js->register('npm.deflateRaw', $moduleHost->deflateRaw(...));
        }
        $source = read($bundle ?? dirname(__DIR__) . '/resources/puppeteer.js');
        $this->js->eval($source, typescript: false);
        $this->dispatch = $this->js->eval('globalThis.__quickjsDispatch');
    }

    /**
     * @template T of RemoteObject
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function getStaticClass(string $class): RemoteObject
    {
        $name = array_search($class, Internal\GeneratedRegistry::CLASSES, true);
        if (false === $name) {
            throw new InvalidArgumentException('Unknown generated class: ' . $class);
        }
        $object = $this->call(0, 'staticClass', [$name])->await();
        if (!$object instanceof $class) {
            throw new UnexpectedValueException('Unexpected static class: ' . $name);
        }

        return $object;
    }

    /** @return Future<Browser> */
    public function connect(string $endpoint, array $options = [], ?Cancellation $cancellation = null): Future
    {
        if ($this->closed || $this->connecting || null !== $this->socket) {
            return Future::error(new LogicException('Client can only connect once'));
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
            } catch (Throwable $error) {
                $this->stop($error);
                throw $error;
            }
            if ($this->closed) {
                $socket->close();
                throw new RuntimeException('QuickJS client closed while connecting');
            }
            $this->socket = $socket;
            async(function () use ($socket): void {
                try {
                    while (!$this->closed && ($message = $socket->receive())) {
                        $this->deliverMessage($message->buffer());
                    }
                    if (!$this->closed) {
                        $this->stop(new Internal\TransportClosedException('Browser transport closed'));
                    }
                } catch (Throwable $e) {
                    $this->stop($e);
                }
            })->ignore();
            try {
                $browser = $this->call(0, 'connect', [$options], cancellation: $cancellation)->await();
            } catch (Throwable $error) {
                $this->stop($error);
                throw $error;
            }
            if (!$browser instanceof Browser) {
                throw new UnexpectedValueException('Puppeteer connect did not return a Browser');
            }

            return $browser;
        });
    }

    /** @return Future<mixed> */
    public function call(int $object, string $method, array $arguments, string $operation = 'call', ?Cancellation $cancellation = null): Future
    {
        if ($this->closed) {
            return Future::error(new RuntimeException('QuickJS client is closed', previous: $this->closeCause));
        }
        try {
            $cancellation?->throwIfRequested();
            $encoded = $this->encode($arguments);
        } catch (Throwable $error) {
            return Future::error($error);
        }
        $id = ++$this->sequence;
        $deferred = $this->pending[$id] = new DeferredFuture();
        try {
            $this->deliver('call', ['id' => $id, 'object' => $object, 'method' => $method, 'operation' => $operation, 'args' => $encoded]);
        } catch (Throwable $e) {
            $this->stop($e);
        }
        $automaticTimeout = null === $cancellation && null !== $this->protocolTimeout;
        $cancellation ??= $automaticTimeout ? new TimeoutCancellation($this->protocolTimeout / 1000.0) : null;
        if (null === $cancellation) {
            return $deferred->getFuture();
        }

        return async(function () use ($deferred, $cancellation, $id, $automaticTimeout, $operation): mixed {
            try {
                return $deferred->getFuture()->await($cancellation);
            } catch (CancelledException $error) {
                if (($this->pending[$id] ?? null) === $deferred) {
                    unset($this->pending[$id]);
                }
                if (!$automaticTimeout && 'stream' !== $operation) {
                    $this->stop($error);
                }
                throw $error;
            }
        });
    }

    private function deliverMessage(string $message): void
    {
        $length = strlen($message);
        if ($length <= 65536) {
            $this->deliver('message', $message);

            return;
        }
        // CDP JSON can exceed the native single-value budget even when its decoded result fits.
        for ($offset = 0; $offset < $length;) {
            $end = min($length, $offset + 65536);
            // Never split a UTF-8 sequence at the native string conversion boundary.
            while ($end < $length && (ord($message[$end]) & 0xC0) === 0x80) {
                --$end;
            }
            $this->deliver('messageChunk', substr($message, $offset, $end - $offset));
            $offset = $end;
            $yield = new DeferredFuture();
            EventLoop::defer(static fn () => $yield->complete(null));
            $yield->getFuture()->await();
            if ($this->closed) {
                return;
            }
        }
        $this->deliver('messageEnd', null);
    }

    private function deliver(string $kind, mixed $payload): void
    {
        if ($this->closed) {
            return;
        }
        $slot = $this->dispatchSlots->acquire();
        ++$this->dispatching;
        try {
            if ($this->isClosed()) {
                return;
            }
            $this->dispatch->post($kind, $payload);
            ++$this->driveGeneration;
            $this->drive();
        } finally {
            if (0 === --$this->dispatching) {
                $idle = $this->dispatchIdle;
                $this->dispatchIdle = null;
                $idle?->complete(null);
            }
            $slot->release();
        }
    }

    private function drive(): void
    {
        if ($this->draining || $this->closed) {
            return;
        }
        $this->draining = true;
        async(function (): void {
            try {
                while (!$this->closed) {
                    $generation = $this->driveGeneration;
                    $messages = $this->js->awaitMessages();
                    $transportClosed = false;
                    foreach ($messages as [$kind, $payload]) {
                        if ('close' === $kind) {
                            $transportClosed = true;
                        } else {
                            $this->receive($kind, $payload);
                        }
                    }
                    if ($transportClosed) {
                        $this->stop(reason: 'Puppeteer requested transport close');
                    }
                    if ([] === $this->pending && $generation === $this->driveGeneration) {
                        break;
                    }
                }
            } catch (Throwable $error) {
                $this->stop($error);
            } finally {
                $this->draining = false;
            }
        })->ignore();
    }

    private function receive(string $kind, mixed $payload): void
    {
        if ($this->closed) {
            return;
        }
        if ('fatal' === $kind) {
            throw $this->guestError($payload);
        }
        if ('send' === $kind) {
            $this->writes[] = $payload;
            $this->write();

            return;
        }
        if ('releaseCallback' === $kind) {
            unset($this->callbacks[(int) $payload]);

            return;
        }
        if ('log' === $kind) {
            $level = is_array($payload) ? (string) ($payload['level'] ?? 'info') : 'info';
            $message = is_array($payload) ? (string) ($payload['message'] ?? '') : (string) $payload;
            $this->logger->log($level, $message);

            return;
        }
        if ('clearTimer' === $kind) {
            $id = (int) $payload;
            if (isset($this->timers[$id])) {
                EventLoop::cancel($this->timers[$id]);
                unset($this->timers[$id]);
            }

            return;
        }
        if ('httpCancel' === $kind) {
            $this->http?->close((int) $payload);

            return;
        }
        if ('result' === $kind) {
            $future = $this->pending[$payload['id']] ?? null;
            unset($this->pending[$payload['id']]);
            if (null === $future) {
                return;
            }
            try {
                if (isset($payload['error'])) {
                    $future->error($this->guestError($payload['error']));
                } else {
                    $future->complete($this->decode($payload['value']));
                }
            } catch (Throwable $error) {
                $future->error($error);
            }

            return;
        }
        $data = $payload;
        if ('timer' === $kind) {
            $id = $data['id'];
            $callback = function () use ($id): void {
                unset($this->timers[$id]);
                try {
                    $this->deliver('timer', (string) $id);
                } catch (Throwable $e) {
                    $this->stop($e);
                }
            };
            $this->timers[$id] = $data['milliseconds'] > 0
                ? EventLoop::delay($data['milliseconds'] / 1000, $callback)
                : EventLoop::defer($callback);
        } elseif (in_array($kind, ['callback', 'filesystem', 'http', 'crypto'], true)) {
            if ('http' === $kind && 'start' === $data['operation']) {
                ($this->http ??= new Internal\HostHttp())->begin($data['token']);
            }
            $fn = match ($kind) {
                'callback' => $this->callbacks[$data['callback']] ?? null,
                'filesystem' => fn (...$args) => ($this->filesystem ??= new Internal\HostFilesystem())->call($data['operation'], $args),
                'crypto' => Internal\HostCrypto::digest(...),
                'http' => fn ($options) => match ($data['operation']) {
                    'start' => $this->http?->start($data['token'], $options),
                    'read' => $this->http?->read($data['token']),
                    'close' => $this->http?->close($data['token']),
                    default => throw new RuntimeException('Unknown HTTP operation'),
                },
            };
            async(function () use ($kind, $data, $fn): void {
                if ($this->closed) {
                    return;
                }
                try {
                    if (null === $fn) {
                        throw new RuntimeException('Unknown PHP callback');
                    }
                    $value = $fn(...$this->decode($data['args']));
                    if ($value instanceof Future) {
                        $value = $value->await();
                    }
                    $result = ['id' => $data['id'], 'value' => $this->encode($value)];
                } catch (Throwable $e) {
                    $result = ['id' => $data['id'], 'error' => ['name' => in_array($kind, ['http', 'crypto'], true) ? 'TypeError' : $e::class, 'message' => $e->getMessage()]];
                }
                if ($this->isClosed()) {
                    return;
                }
                try {
                    $this->deliver('callbackResult', $result);
                } catch (Throwable $e) {
                    $this->stop($e);
                }
            })->ignore();
        }
    }

    private function write(): void
    {
        if ($this->writing || !$this->writes || !$this->socket) {
            return;
        }
        $socket = $this->socket;
        $this->writing = true;
        async(function () use ($socket): void {
            try {
                while (!$this->closed && $this->writes) {
                    $message = array_shift($this->writes);
                    $socket->sendText($message);
                }
            } catch (Throwable $e) {
                $this->stop($e);
            } finally {
                $this->writing = false;
            }
        })->ignore();
    }

    private function encode(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Bridge arguments exceed maximum depth 64');
        }
        if ($value instanceof RemoteObject) {
            if (!$value->belongsTo($this)) {
                throw new InvalidArgumentException('Remote object belongs to another client or has been released');
            }

            return ['$quickjs' => 'object', 'id' => $value->remoteId()];
        }
        if ($value instanceof JsFunction || $value instanceof Closure) {
            if (null === $this->functionIds) {
                /** @var WeakMap<Closure|JsFunction, int> $ids */
                $ids = new WeakMap();
                $this->functionIds = $ids;
            }
            $id = $this->functionIds[$value] ?? null;
            if (null === $id) {
                $id = ++$this->functionSequence;
                $this->functionIds[$value] = $id;
            }
            if ($value instanceof JsFunction) {
                if (null === $this->functionReferences) {
                    /** @var WeakMap<JsFunction, Internal\FunctionReference> $references */
                    $references = new WeakMap();
                    $this->functionReferences = $references;
                }
                if (!isset($this->functionReferences[$value])) {
                    $this->functionReferences[$value] = new Internal\FunctionReference($this, $id);
                }

                return ['$quickjs' => 'function', 'id' => $id, 'source' => $value->source];
            }
            $this->callbacks[$id] = $value;

            return ['$quickjs' => 'callback', 'id' => $id];
        }
        if (is_array($value)) {
            $record = array_map(fn (mixed $item): mixed => $this->encode($item, $depth + 1), $value);

            return array_key_exists('$quickjs', $record) ? ['$quickjs' => 'record', 'value' => $record] : $record;
        }

        return $value;
    }

    private function decode(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (($value['$quickjs'] ?? null) === 'record') {
            return array_map($this->decode(...), $value['value']);
        }
        if (($value['$quickjs'] ?? null) === 'stream') {
            $id = $value['id'];
            if (isset($value['lease'])) {
                $this->streamLeases[$id] = $value['lease'];
            }
            $stream = ($this->streams[$id] ?? null)?->get();
            if (null === $stream) {
                $stream = new Internal\ReadableStream($this, $id);
                $this->streams[$id] = WeakReference::create($stream);
            }

            return $stream;
        }
        if (($value['$quickjs'] ?? null) === 'object') {
            $id = $value['id'];
            if (isset($value['lease'])) {
                $this->objectLeases[$id] = $value['lease'];
            }
            $object = ($this->objects[$id] ?? null)?->get();
            if (null === $object) {
                $object = RemoteObjectFactory::create($this, $id, $value['class']);
                $this->objects[$id] = WeakReference::create($object);
            }

            return $object;
        }
        if (($value['$quickjs'] ?? null) === 'undefined') {
            return null;
        }
        if (($value['$quickjs'] ?? null) === 'bytes') {
            return $value['value'];
        }
        if (isset($value['$quickjs'])) {
            return $value;
        } // Preserve protocol tags not handled by generated wrappers.

        return array_map($this->decode(...), $value);
    }

    /** @internal Schedule finalizer work outside native callbacks. */
    public function releaseFunctionLater(int $id): void
    {
        $this->deferFinalizer(fn () => $this->deliver('releaseFunction', ['id' => $id]));
    }

    /** @internal Schedule finalizer work outside native callbacks. */
    public function releaseLater(int $id): void
    {
        $lease = $this->objectLeases[$id] ?? null;
        $this->deferFinalizer(function () use ($id, $lease): void {
            if (($this->objects[$id] ?? null)?->get() === null) {
                unset($this->objects[$id], $this->objectLeases[$id]);
                $this->deliver('release', ['id' => $id, 'lease' => $lease]);
            }
        });
    }

    /**
     * @internal
     *
     * @psalm-external-mutation-free
     */
    public function forgetStream(int $id): void
    {
        unset($this->streams[$id]);
    }

    /** @internal Stream finalizers must not re-enter QuickJS. */
    public function cancelStreamLater(int $id, bool $onlyIfUnreferenced = false): void
    {
        $lease = $this->streamLeases[$id] ?? null;
        $this->deferFinalizer(function () use ($id, $onlyIfUnreferenced, $lease): void {
            if ($onlyIfUnreferenced && ($this->streams[$id] ?? null)?->get() !== null) {
                return;
            }
            unset($this->streamLeases[$id]);
            $this->call($id, 'cancel', $onlyIfUnreferenced ? [$lease] : [], 'stream')->ignore();
        });
    }

    private function deferFinalizer(Closure $release): void
    {
        if ($this->closed) {
            return;
        }
        EventLoop::queue(function () use ($release): void {
            try {
                // A native dispatch can overtake a finalizer; a protocol request
                // awaiting external I/O must not hold unrelated objects alive.
                while ($this->dispatching > 0 && !$this->closed) {
                    $this->dispatchIdle ??= new DeferredFuture();
                    $this->dispatchIdle->getFuture()->await();
                }
                if (!$this->closed) {
                    $release();
                }
            } catch (Throwable $error) {
                $this->stop($error);
            }
        });
    }

    public function release(int $id): void
    {
        unset($this->objects[$id], $this->objectLeases[$id]);
        if (!$this->closed) {
            try {
                $this->deliver('release', ['id' => $id]);
            } catch (Throwable $error) {
                $this->stop($error);
            }
        }
    }

    /** @internal
     * @psalm-mutation-free
     */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /** @internal
     * @psalm-external-mutation-free
     */
    public function ownBrowser(Internal\BrowserProcess $process): void
    {
        $this->browserProcess = $process;
    }

    /** @internal */
    public function browserClosed(): void
    {
        try {
            $this->browserProcess?->close();
        } finally {
            $this->browserProcess = null;
            $this->close();
        }
    }

    /** @internal
     * @psalm-mutation-free
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        $this->stop();
    }

    /** @psalm-suppress RedundantPropertyInitializationCheck Unit fixtures may bypass the constructor. */
    private function stop(?Throwable $error = null, string $reason = 'Client::close() was called'): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        // Retain the first cause and its stack even after cleanup or repeated close() calls.
        $this->closeCause = $error ?? new RuntimeException($reason);
        // Drop JS transport callbacks, timers and object roots as well as PHP bookkeeping.
        try {
            ($this->dispatch)('closed', null);
        } catch (Throwable) {
        }
        foreach ($this->timers as $watcher) {
            EventLoop::cancel($watcher);
        }
        $this->timers = [];
        $this->writes = [];
        $this->filesystem?->closeAll();
        $this->http?->closeAll();
        $this->callbacks = [];
        $this->objectLeases = $this->streamLeases = [];
        $this->functionIds = null;
        $this->functionReferences = null;
        $this->objects = [];
        foreach ($this->streams as $stream) {
            $stream->get()?->transportClosed($error);
        }
        $this->streams = [];
        foreach ($this->pending as $future) {
            $future->error($error ?? new RuntimeException('QuickJS client closed'));
        }
        $this->pending = [];
        $this->socket?->close();
        if (isset($this->js)) {
            $this->js->close();
        }
    }

    /** @psalm-pure */
    private function guestError(array $error): RuntimeException
    {
        return new RuntimeException(($error['name'] ?? 'Error') . ': ' . ($error['message'] ?? '') . "\n" . ($error['stack'] ?? ''));
    }
}
