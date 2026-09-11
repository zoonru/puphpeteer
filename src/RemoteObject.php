<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;
use Amp\Future;
final class RemoteObject
{
    /** @psalm-mutation-free */
    public function __construct(private Client $client, public readonly int $id, public readonly string $class) {}
    /** @return Future<mixed> */
    public function __call(string $method, array $arguments): Future
    {
        return $this->client->call($this->id, $method, $arguments);
    }
    public function release(): void { $this->client->release($this->id); }
}
