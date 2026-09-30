<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;
use Closure;
use IteratorAggregate;
use Nesk\Puphpeteer\Puppeteer\ScreenRecording;
use Nesk\Puphpeteer\RemoteObject;
use Override;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;
use function Amp\File\openFile;

/**
 * @internal amp adapter for the generated ScreenRecording API
 *
 * @implements IteratorAggregate<int, string>
 */
abstract class ScreenRecordingStream extends RemoteObject implements ReadableStream, IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private ?ReadableStream $stream = null;
    /** @var Future<null>|null */
    private ?Future $filePump = null;
    /** @var Future<null>|null */
    private ?Future $stop = null;

    /**
     * @internal keep recording files on the PHP host, even with remote Chrome
     *
     * @param array{path?: string, overwrite?: bool, audio?: bool, maxWidth?: int|float, maxHeight?: int|float, frameRate?: int|float, fps?: int|float} $options
     * @param Closure(array): ScreenRecording                                                                                                           $start
     */
    public static function start(array $options, Closure $start): ScreenRecording
    {
        $path = $options['path'] ?? null;
        $overwrite = $options['overwrite'] ?? true;
        unset($options['path'], $options['overwrite']);
        foreach (['maxWidth', 'maxHeight', 'frameRate', 'fps'] as $name) {
            if (isset($options[$name]) && $options[$name] <= 0) {
                throw new RuntimeException("`$name` must be greater than 0.");
            }
        }
        if (null === $path) {
            return $start($options);
        }
        try {
            $directory = dirname($path);
            if (!isDirectory($directory)) {
                createDirectoryRecursively($directory);
            }
            $file = openFile($path, $overwrite ? 'wb' : 'xb');
        } catch (Throwable $error) {
            throw new RuntimeException('Cannot open recording file: ' . $error->getMessage(), previous: $error);
        }
        try {
            $recording = $start($options);
            /** @var self $adapter */
            $adapter = $recording;
            $source = $adapter->stream();
        } catch (Throwable $error) {
            $file->close();
            if (isset($recording)) {
                try {
                    $recording->close();
                } catch (Throwable) {
                }
            }
            throw $error;
        }
        $adapter->filePump = async(static function () use ($recording, $source, $file): null {
            try {
                pipe($source, $file);
            } catch (Throwable $error) {
                // Stop Chrome on write/read failure without waiting on this pump itself.
                try {
                    $recording->stopRemote();
                } catch (Throwable) {
                }
                throw $error;
            } finally {
                try {
                    $source->close();
                } finally {
                    $file->close();
                }
            }

            return null;
        });
        $adapter->filePump->ignore();

        return $recording;
    }

    private function stream(): ReadableStream
    {
        return $this->stream ??= $this->getRemoteStream();
    }

    protected function stopRemote(): void
    {
        parent::invokeRemote('stop', []);
    }

    #[Override]
    protected function invokeRemote(string $method, array $arguments): mixed
    {
        if ('stop' !== $method) {
            return parent::invokeRemote($method, $arguments);
        }
        $this->stop ??= async(function (): null {
            try {
                $this->stopRemote();
            } catch (Throwable $error) {
                $this->stream?->close();
                // Wait for file cleanup, preserving the original stop error.
                try {
                    $this->filePump?->await();
                } catch (Throwable) {
                }
                throw $error;
            }
            $this->filePump?->await();

            return null;
        });

        return $this->stop->await();
    }

    #[Override]
    public function read(?Cancellation $cancellation = null): ?string
    {
        if (null !== $this->filePump) {
            try {
                $this->filePump->await($cancellation);
            } catch (CancelledException $error) {
                try {
                    $this->close();
                } catch (Throwable) {
                }
                throw $error;
            }

            return null;
        }

        return $this->stream()->read($cancellation);
    }

    #[Override]
    public function isReadable(): bool
    {
        return !$this->isClosed();
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->filePump?->isComplete() ?? $this->stream?->isClosed() ?? false;
    }

    #[Override]
    public function onClose(Closure $onClose): void
    {
        if (null !== $this->filePump) {
            $this->filePump->finally($onClose)->ignore();
        } else {
            $this->stream()->onClose($onClose);
        }
    }

    #[Override]
    public function close(): void
    {
        if (null === $this->filePump && $this->isClosed()) {
            return;
        }
        try {
            $this->invokeRemote('stop', []);
        } finally {
            $this->stream()->close();
        }
    }
}
