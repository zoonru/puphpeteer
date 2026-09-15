<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Nesk\Puphpeteer\Internal\GeneratedRegistry;
use Nesk\Puphpeteer\Puppeteer\Page;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class CompatibilityTest extends TestCase
{
    private function canonicalName(string $alias): string
    {
        if (!class_exists($alias)) {
            throw new RuntimeException('Missing alias: ' . $alias);
        }

        return (new ReflectionClass($alias))->getName();
    }

    public function testResourceAliasesReferToTheSameClasses(): void
    {
        foreach (GeneratedRegistry::CLASSES as $name => $class) {
            $alias = 'Nesk\\Puphpeteer\\Resources\\' . $name;
            self::assertSame($class, $this->canonicalName($alias));
        }
        $page = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(\Nesk\Puphpeteer\Resources\Page::class, $page);
        $accept = static fn (\Nesk\Puphpeteer\Resources\Page $value): Page => $value;
        self::assertSame($page, $accept($page));
    }

    public function testOnlyResourceAliasesAreRegistered(): void
    {
        foreach (GeneratedRegistry::CLASSES as $name => $class) {
            self::assertFalse(class_exists('Nesk\\Puphpeteer\\' . $name));
        }
        self::assertFalse(class_exists('Nesk\\Puphpeteer\\Puppeteer'));
        self::assertFalse(class_exists('Nesk\\Rialto\\Data\\JsFunction'));
    }
}
