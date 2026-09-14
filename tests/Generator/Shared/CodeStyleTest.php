<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Generator\Shared;

use PHPUnit\Framework\TestCase;
use Zoon\Puphpeteer\Tooling\CodeStyle;

final class CodeStyleTest extends TestCase
{
    public function testImportsClassesWithoutChangingQuotedPhpDocTypes(): void
    {
        $source = <<<'CODE'
            <?php
            namespace Demo;
            class Example extends \Vendor\BaseClass {
                /** @param "\\"|"\n"|"\r" $key */
                public function press(string $key): void { throw new \RuntimeException('key:'.$key); }
            }
            CODE;
        $formatted = CodeStyle::format($source);
        self::assertStringContainsString('use RuntimeException;', $formatted);
        self::assertStringContainsString('use Vendor\BaseClass;', $formatted);
        self::assertStringContainsString(trim(explode("\n", $source)[3]), $formatted);
        self::assertStringContainsString("new RuntimeException('key:' . \$key)", $formatted);
        self::assertSame($formatted, CodeStyle::format($formatted));
    }
}
