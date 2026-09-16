<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Closure;
use IteratorAggregate;
use Nesk\Puphpeteer\RemoteObject;
use Override;

/**
 * @internal amp adapter for the generated ScreenRecording API
 *
 * @implements IteratorAggregate<int, string>
 */
abstract class ScreenRecordingStream extends RemoteObject implements ReadableStream, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private ?ReadableStream $stream = null;

    private function stream(): ReadableStream
    {
        return $this->stream ??= $this->getRemoteStream();
    }

    #[Override]
    public function read(?Cancellation $cancellation = null): ?string
    {
        return $this->stream()->read($cancellation);
    }

    #[Override]
    public function isReadable(): bool
    {
        return $this->stream?->isReadable() ?? true;
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->stream?->isClosed() ?? false;
    }

    #[Override]
    public function onClose(Closure $onClose): void
    {
        $this->stream()->onClose($onClose);
    }

    #[Override]
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }
        $stream = $this->stream();
        try {
            $this->invokeRemote('stop', []);
        } finally {
            $stream->close();
        }
    }
}
