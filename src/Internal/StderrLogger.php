<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

use function Amp\async;

/** @internal */
final class StderrLogger extends AbstractLogger
{
    #[Override]
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $line = '[QuickJS] [' . $level . '] ' . $this->interpolate((string) $message, $context) . "\n";
        async(static fn () => \Amp\ByteStream\getStderr()->write($line))->ignore();
    }

    /** @psalm-pure */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $name => $value) {
            if (null === $value || is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $name . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
