<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Internal;

use Nesk\Puphpeteer\Client;

/** @internal A weak-key lease on the guest's cached JsFunction identity. */
final class FunctionReference
{
    /** @var \WeakReference<Client> */
    private \WeakReference $client;

    public function __construct(Client $client, private readonly int $id)
    {
        $this->client = \WeakReference::create($client);
    }

    public function __destruct()
    {
        $this->client->get()?->releaseFunctionLater($this->id);
    }
}
