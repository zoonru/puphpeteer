<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Support\Shared;

/**
 * Minimal async bridge for tests of PHP lifecycle and host services.
 *
 * @psalm-immutable
 */
final class AsyncGuest
{
    /** @psalm-pure */
    public static function wrap(string $source): string
    {
        return <<<'JS'
        globalThis.__testEmit = (kind, value) => quickjs.postMessage([kind, value]);
        JS . "\n" . $source;
    }
}
