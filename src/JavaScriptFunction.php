<?php
declare(strict_types=1);
namespace Nesk\Puphpeteer;
/** @psalm-immutable */
final readonly class JavaScriptFunction
{
    /** @psalm-mutation-free */
    public function __construct(public string $source) {}
}
