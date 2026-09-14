<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer\Internal;

/** @internal Filesystem operations requested by the Puppeteer environment. */
final class HostFilesystem
{
    /** @var array<int, resource> */
    private array $handles = [];
    private int $sequence = 0;

    public function call(string $operation, array $arguments): mixed
    {
        // Convert PHP I/O warnings into rejected JS promises; never silently lose writes.
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException($message);
        });
        try {
            return match ($operation) {
                'read' => base64_encode($this->read($arguments[0])),
                'write' => $this->write($arguments[0], $arguments[1]),
                'mkdir' => $this->mkdir($arguments[0], $arguments[1]),
                'open' => $this->open($arguments[0]),
                'append' => $this->append($arguments[0], $arguments[1]),
                'close' => $this->close($arguments[0]),
                default => throw new \InvalidArgumentException('Unknown filesystem operation: ' . $operation),
            };
        } finally { restore_error_handler(); }
    }

    private function read(string $path): string
    {
        $data = file_get_contents($path);
        if ($data === false) { throw new \RuntimeException('Cannot read file: ' . $path); }
        return $data;
    }

    private function write(string $path, string $data): null
    {
        $id = $this->open($path);
        try { $this->append($id, $data); }
        finally { $this->close($id); }
        return null;
    }

    private function mkdir(string $path, bool $recursive): null
    {
        if ($recursive && is_dir($path)) { return null; }
        if (!mkdir($path, 0777, $recursive)) { throw new \RuntimeException('Cannot create directory: ' . $path); }
        return null;
    }

    private function open(string $path): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) { throw new \RuntimeException('Cannot open file: ' . $path); }
        $id = ++$this->sequence;
        $this->handles[$id] = $handle;
        return $id;
    }

    private function append(int $id, string $data): null
    {
        $handle = $this->handles[$id] ?? throw new \RuntimeException('Unknown file handle');
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) { throw new \RuntimeException('Cannot write file'); }
            $offset += $written;
        }
        return null;
    }

    private function close(int $id): null
    {
        $handle = $this->handles[$id] ?? throw new \RuntimeException('Unknown file handle');
        unset($this->handles[$id]);
        fclose($handle);
        return null;
    }

    public function closeAll(): void
    {
        foreach ($this->handles as $handle) { fclose($handle); }
        $this->handles = [];
    }

    public function __destruct() { $this->closeAll(); }
}
