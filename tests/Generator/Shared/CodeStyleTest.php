<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Generator\Shared;

use Nesk\Puphpeteer\Console\Command\Generate\CodeStyle;
use PHPUnit\Framework\TestCase;

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
        $batch = CodeStyle::formatMany(['first' => $source, 'second' => '<?php namespace Other; class Second { public function run(): void { throw new \\LogicException(); } }']);
        self::assertSame(['first', 'second'], array_keys($batch));
        self::assertStringContainsString('use LogicException;', $batch['second']);
        self::assertSame([], CodeStyle::formatMany([]));
        $formatted = $batch['first'];
        self::assertStringContainsString('use RuntimeException;', $formatted);
        self::assertStringContainsString('use Vendor\BaseClass;', $formatted);
        self::assertStringContainsString(trim(explode("\n", $source)[3]), $formatted);
        self::assertStringContainsString("new RuntimeException('key:' . \$key)", $formatted);
        self::assertSame($formatted, CodeStyle::format($formatted));
    }
}
