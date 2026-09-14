<?php

declare(strict_types=1);

namespace Zoon\Puphpeteer\Tooling;

use RuntimeException;

/** An upstream declaration needs an explicit mapping; other declarations may proceed. */
final class UnrepresentableDeclaration extends RuntimeException
{
}
