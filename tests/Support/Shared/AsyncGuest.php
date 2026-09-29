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
        let pending = false;
        globalThis.__testEmit = (kind, value) => {
          quickjs.postMessage([kind, value]);
          pending = true;
        };
        globalThis.__quickjsDrain = async () => {
          const ready = pending;
          pending = false;
          return ready;
        };
        JS . "\n" . $source;
    }
}
