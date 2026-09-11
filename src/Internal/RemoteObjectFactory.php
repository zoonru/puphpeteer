<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\RemoteObject;

/**
 * Resolves public guest type names only through the generated public API allowlist.
 * @psalm-immutable
 */
final class RemoteObjectFactory
{
    /** @psalm-pure */
    public static function create(Client $client, int $id, string $guestClass): RemoteObject
    {
        $class = GeneratedRegistry::CLASSES[$guestClass] ?? RemoteObject::class;
        return new $class($client, $id, $guestClass);
    }
}
