<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

final class ProtocolException extends \RuntimeException
{
    public function __construct(string $method, string $message, int $code = 0)
    {
        parent::__construct("Protocol error ({$method}): {$message}", $code);
    }
}
