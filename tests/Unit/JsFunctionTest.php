<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Unit;
use Nesk\Puphpeteer\JsFunction;
use PHPUnit\Framework\TestCase;

final class JsFunctionTest extends TestCase
{
    public function testRawSourceAndFactories(): void
    {
        self::assertSame('(x) => x', (new JsFunction('(x) => x'))->source);
        self::assertSame(JsFunction::createWithBody('return 42;')->source, JsFunction::create('return 42;')->source);
        self::assertSame(JsFunction::createWithParameters(['x'])->body('return x;')->source, JsFunction::create(['x'], 'return x;')->source);
        self::assertSame(JsFunction::createWithScope(['x' => 42])->body('return x;')->source, JsFunction::create('return x;', ['x' => 42])->source);
    }

    public function testBuilderIsImmutableAndSupportsDefaultsScopeAndAsync(): void
    {
        $original = JsFunction::createWithParameters(['x', 'y' => 2]);
        $function = $original->body('return x + y + offset;')->scope(['offset' => 3])->async();
        self::assertSame("function(x, y = 2) {\n\n}", $original->source);
        self::assertSame("async function(x, y = 2) {\nvar offset = 3;\nreturn x + y + offset;\n}", $function->source);
        self::assertStringStartsWith('async function(', JsFunction::createWithAsync()->source);
    }

    public function testInvalidScopeNamesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsFunction::createWithScope(['a;evil()' => 1]);
    }
}
