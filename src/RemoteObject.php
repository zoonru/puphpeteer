<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;
class RemoteObject
{
    private bool $released = false;
    /** @psalm-mutation-free */
    public function __construct(private Client $client, private readonly int $id, private readonly string $class) {}
    public function __call(string $method, array $arguments): mixed
    {
        return $this->invokeRemote($method, $arguments);
    }
    protected function invokeRemote(string $method, array $arguments): mixed
    {
        $this->assertLive();
        if ($this instanceof Browser && $method === 'wsEndpoint') { return $this->client->endpoint(); }
        if ($this instanceof Browser && $this->client->isClosed() && in_array($method, ['close', 'disconnect'], true)) {
            if ($method === 'close') { $this->client->browserClosed(); }
            return null;
        }
        $path = null;
        if (in_array($method, ['screenshot', 'pdf'], true) && isset($arguments[0]['path'])) {
            $path = $arguments[0]['path'];
            unset($arguments[0]['path']);
            if ($method === 'screenshot' && !isset($arguments[0]['type'])) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $arguments[0]['type'] = match ($extension) { 'jpg', 'jpeg' => 'jpeg', 'webp' => 'webp', default => 'png' };
            }
        }
        try { $result = $this->client->call($this->id, $method, $arguments)->await(); }
        finally {
            if ($this instanceof Browser && $method === 'close') { $this->client->browserClosed(); }
        }
        if ($path !== null) {
            $bytes = ($arguments[0]['encoding'] ?? '') === 'base64' ? base64_decode($result, true) : $result;
            if (!is_string($bytes) || file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Cannot write browser output: ' . $path);
            }
        }
        return $result;
    }
    /** @internal @psalm-mutation-free */
    public function remoteId(): int { return $this->id; }
    public function __get(string $name): mixed { return $this->getRemote($name); }
    protected function getRemote(string $name): mixed
    {
        $this->assertLive();
        if ($this instanceof Browser && $name === 'connected' && $this->client->isClosed()) { return false; }
        return $this->client->call($this->id, $name, [], 'get')->await();
    }
    /**
     * func_get_args() already includes positional variadic arguments.
     * Append only the named variadic arguments it omits, in call order.
     * @param list<mixed> $arguments
     * @param array<array-key, mixed> $variadic
     * @return list<mixed>
     * @psalm-pure
     */
    protected static function mergeNamedArguments(array $arguments, array $variadic): array
    {
        foreach ($variadic as $name => $value) {
            if (is_string($name)) {
                $arguments[] = $value;
            }
        }
        return $arguments;
    }
    /**
     * @internal
     * @psalm-mutation-free
     */
    public function belongsTo(Client $client): bool { return !$this->released && $this->client === $client; }
    /** @psalm-mutation-free */
    private function assertLive(): void
    {
        if ($this->released) { throw new \RuntimeException('Remote object has been released'); }
    }
    public function release(): void
    {
        if ($this->released) { return; }
        $this->released = true;
        $this->client->release($this->id);
    }
    /**
     * Reflection-based tooling may instantiate a wrapper without its constructor.
     * @psalm-suppress RedundantCondition
     */
    public function __destruct() { if (!$this->released && isset($this->client, $this->id)) { $this->client->releaseLater($this->id); } }
}
