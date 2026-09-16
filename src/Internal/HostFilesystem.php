<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\File\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function Amp\File\createDirectory;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;
use function Amp\File\openFile;
use function Amp\File\read as readFile;
use function Amp\File\write as writeFile;

/** @internal Filesystem operations requested by the Puppeteer environment. */
final class HostFilesystem
{
    /** @var array<int, File> */
    private array $handles = [];
    private int $sequence = 0;

    public function call(string $operation, array $arguments): mixed
    {
        try {
            return match ($operation) {
                'read' => base64_encode(readFile($arguments[0])),
                'write' => $this->write($arguments[0], $arguments[1]),
                'mkdir' => $this->mkdir($arguments[0], $arguments[1]),
                'open' => $this->open($arguments[0]),
                'openRecording' => $this->openRecording($arguments[0], $arguments[1]),
                'append' => $this->append($arguments[0], $arguments[1]),
                'close' => $this->close($arguments[0]),
                default => throw new InvalidArgumentException('Unknown filesystem operation: ' . $operation),
            };
        } catch (InvalidArgumentException|RuntimeException $error) {
            throw $error;
        } catch (Throwable $error) {
            $messages = [];
            for ($cause = $error; null !== $cause; $cause = $cause->getPrevious()) {
                $messages[] = $cause->getMessage();
            }
            throw new RuntimeException("Filesystem $operation failed: " . implode(': ', array_unique($messages)), previous: $error);
        }
    }

    private function write(string $path, string $data): null
    {
        writeFile($path, $data);

        return null;
    }

    private function mkdir(string $path, bool $recursive): null
    {
        if ($recursive && isDirectory($path)) {
            return null;
        }
        $recursive ? createDirectoryRecursively($path) : createDirectory($path);

        return null;
    }

    private function openRecording(string $path, bool $overwrite): int
    {
        $directory = dirname($path);
        if (!isDirectory($directory)) {
            $this->mkdir($directory, true);
        }

        return $this->open($path, $overwrite ? 'wb' : 'xb');
    }

    private function open(string $path, string $mode = 'wb'): int
    {
        $id = ++$this->sequence;
        $this->handles[$id] = openFile($path, $mode);

        return $id;
    }

    private function append(int $id, string $data): null
    {
        $handle = $this->handles[$id] ?? throw new RuntimeException('Unknown file handle');
        $handle->write($data);

        return null;
    }

    private function close(int $id): null
    {
        $handle = $this->handles[$id] ?? throw new RuntimeException('Unknown file handle');
        unset($this->handles[$id]);
        $handle->close();

        return null;
    }

    public function closeAll(): void
    {
        foreach ($this->handles as $handle) {
            $handle->close();
        }
        $this->handles = [];
    }
}
