<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Value;

/** An arbitrary precision JavaScript integer, without a PHP extension dependency. */
final class BigInt implements \Stringable
{
    public readonly string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^-?[0-9]+$/D', $value)) {
            throw new \InvalidArgumentException('BigInt must be a decimal integer');
        }
        $digits = ltrim(ltrim($value, '-'), '0');
        $this->value = $digits === '' ? '0' : (str_starts_with($value, '-') ? '-' : '') . $digits;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
