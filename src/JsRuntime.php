<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer;

use Nesk\Puphpeteer\Internal\NpmModuleHost;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** One QuickJS context shared by npm modules and a Puppeteer instance. */
final class JsRuntime
{
    private Client $client;

    public function __construct(?string $moduleRoot = null, ?LoggerInterface $logger = null)
    {
        $this->client = new Client(logger: $logger, moduleHost: null === $moduleRoot ? null : new NpmModuleHost($moduleRoot));
    }

    /** Load an npm package or a CommonJS file in this runtime. */
    public function require(string $specifier): mixed
    {
        return $this->client()->call(0, 'require', [$specifier], 'runtime')->await();
    }

    /** Execute a function in this runtime, not in a browser page. */
    public function run(JsFunction $function, mixed ...$arguments): mixed
    {
        return $this->client()->call(0, 'run', [$function, ...$arguments], 'runtime')->await();
    }

    /**
     * @internal
     *
     * @psalm-mutation-free
     */
    public function client(): Client
    {
        if ($this->client->isClosed()) {
            throw new RuntimeException('JsRuntime is closed; create a new runtime for another browser');
        }

        return $this->client;
    }
}
