<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer\Internal;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Nesk\Puphpeteer\Client;
use function Amp\delay;

/** @internal A byte stream owned by QuickJS, consumed on demand by PHP.
 * @implements \IteratorAggregate<int, string>
 */
final class ReadableStream implements \Amp\ByteStream\ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use \Amp\ForbidCloning;
    use \Amp\ForbidSerialization;

    private bool $closed = false;
    private bool $pending = false;
    private ?StreamException $error = null;
    private readonly DeferredCancellation $cancellation;
    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;

    public function __construct(private readonly Client $client, private readonly int $id)
    {
        $this->cancellation = new DeferredCancellation();
        $this->onClose = new DeferredFuture();
    }

    #[\Override]
    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->error !== null) { throw $this->error; }
        if ($this->pending) { throw new PendingReadError(); }
        if ($this->closed) { return null; }
        $this->pending = true;
        $combined = new CompositeCancellation($this->cancellation->getCancellation(), ...($cancellation === null ? [] : [$cancellation]));
        try {
            // Even buffered JS chunks must let timers and other fibers run.
            delay(0, cancellation: $combined);
            $chunk = $this->client->call($this->id, 'read', [], 'stream', $combined)->await();
            if ($chunk === null) { $this->finish(); return null; }
            if (!is_string($chunk) || strlen($chunk) > 65536) { throw new StreamException('Invalid QuickJS stream chunk'); }
            return $chunk;
        } catch (CancelledException $error) {
            $closed = $this->closed;
            $this->close();
            if ($this->error !== null) { throw $this->error; }
            if ($closed && !($cancellation?->isRequested() ?? false)) { return null; }
            throw $error;
        } catch (\Throwable $error) {
            $this->error = $error instanceof StreamException ? $error : new StreamException($error->getMessage(), previous: $error);
            $this->close();
            throw $this->error;
        } finally { $this->pending = false; }
    }

    /** @psalm-mutation-free */
    #[\Override]
    public function isReadable(): bool { return !$this->closed; }
    /** @psalm-mutation-free */
    #[\Override]
    public function isClosed(): bool { return $this->closed; }
    #[\Override]
    public function onClose(\Closure $onClose): void { $this->onClose->getFuture()->finally($onClose); }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) { return; }
        $this->finish();
        $this->client->cancelStreamLater($this->id);
    }

    /** @internal Called by the transport when the browser connection closes. */
    public function transportClosed(?\Throwable $error): void
    {
        if ($this->closed) { return; }
        $this->error = new StreamException($error?->getMessage() ?? 'QuickJS client closed', previous: $error);
        $this->finish();
    }

    private function finish(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->client->forgetStream($this->id);
        $this->cancellation->cancel();
        $this->onClose->complete(null);
    }

    public function __destruct()
    {
        if ($this->closed) { return; }
        $this->finish();
        $this->client->cancelStreamLater($this->id, onlyIfUnreferenced: true);
    }
}
