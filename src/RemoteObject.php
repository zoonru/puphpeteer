<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;
class RemoteObject
{
    /** @psalm-mutation-free */
    public function __construct(private Client $client, private readonly int $id, private readonly string $class) {}
    public function __call(string $method, array $arguments): mixed
    {
        return $this->invokeRemote($method, $arguments);
    }
    protected function invokeRemote(string $method, array $arguments): mixed
    {
        if ($this instanceof Browser && $method === 'wsEndpoint') { return $this->client->endpoint(); }
        $path = null;
        if (in_array($method, ['screenshot', 'pdf'], true) && isset($arguments[0]['path'])) {
            $path = $arguments[0]['path'];
            unset($arguments[0]['path']);
            if ($method === 'screenshot' && !isset($arguments[0]['type'])) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $arguments[0]['type'] = match ($extension) { 'jpg', 'jpeg' => 'jpeg', 'webp' => 'webp', default => 'png' };
            }
        }
        $result = $this->client->call($this->id, $method, $arguments)->await();
        if ($path !== null) {
            $bytes = ($arguments[0]['encoding'] ?? '') === 'base64' ? base64_decode($result, true) : $result;
            if (!is_string($bytes) || file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Cannot write browser output: ' . $path);
            }
        }
        if ($this instanceof Browser && $method === 'close') { $this->client->browserClosed(); }
        return $result;
    }
    /** @internal @psalm-mutation-free */
    public function remoteId(): int { return $this->id; }
    public function __get(string $name): mixed { return $this->getRemote($name); }
    protected function getRemote(string $name): mixed
    {
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
    public function release(): void { $this->client->release($this->id); }
}
