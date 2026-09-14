<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Nesk\Puphpeteer\Internal\GeneratedRegistry;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer;
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

    public function testOriginalImportsReferToTheNamespacedPuppeteerApi(): void
    {
        self::assertSame(Puppeteer\Puppeteer::class, $this->canonicalName('Nesk\\Puphpeteer\\Puppeteer'));
        foreach (GeneratedRegistry::CLASSES as $name => $class) {
            self::assertSame('Nesk\\Puphpeteer\\Puppeteer', (new ReflectionClass($class))->getNamespaceName());
            self::assertSame($class, $this->canonicalName('Nesk\\Puphpeteer\\' . $name));
        }
        $legacy = new Puppeteer();
        self::assertInstanceOf(Puppeteer\Puppeteer::class, $legacy);
        self::assertSame((new Puppeteer\Puppeteer())->defaultArgs(), $legacy->defaultArgs());
    }

    public function testOldJsFunctionImportSupportsFactoriesAndTypeHints(): void
    {
        $function = \Nesk\Rialto\Data\JsFunction::createWithBody('return 42;');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Nesk\Rialto\Data\JsFunction::class, new JsFunction('() => 42'));
        self::assertSame(JsFunction::createWithBody('return 42;')->source, $function->source);
    }
}
