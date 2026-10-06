<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use InvalidArgumentException;

/** @internal Cryptographic primitives; the leading byte forces native binary transport. */
final class HostCrypto
{
    public static function randomBytes(int $length): string
    {
        if ($length < 0 || $length > 65536) {
            throw new InvalidArgumentException('Random byte quota exceeded');
        }

        return "\xff" . ($length ? random_bytes($length) : '');
    }

    /** @psalm-pure */
    public static function digest(string $algorithm, string $data): string
    {
        $algorithms = ['SHA-1' => 'sha1', 'SHA-256' => 'sha256', 'SHA-384' => 'sha384', 'SHA-512' => 'sha512'];
        $name = $algorithms[$algorithm] ?? throw new InvalidArgumentException('Unsupported digest algorithm');

        return "\xff" . hash($name, $data, true);
    }
}
