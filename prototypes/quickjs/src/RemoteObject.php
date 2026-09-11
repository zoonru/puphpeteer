<?php
declare(strict_types=1);
namespace PuphpeteerQuickJs;
use Amp\Future;
final class RemoteObject
{
    public function __construct(private Client $client, public readonly int $id, public readonly string $class) {}
    public function __call(string $method, array $arguments): Future
    {
        return $this->client->call($this->id, $method, $arguments);
    }
    public function release(): void { $this->client->release($this->id); }
}
