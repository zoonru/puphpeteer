<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

/** JavaScript exceptions can contain any value, unlike PHP throwables. */
final class EvaluationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $name = 'Error',
        public readonly mixed $value = null,
        public readonly string $javascriptStack = '',
    ) {
        parent::__construct($message);
    }
}
