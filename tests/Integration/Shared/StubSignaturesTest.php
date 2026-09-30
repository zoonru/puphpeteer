<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class StubSignaturesTest extends TestCase
{
    public function testPsalmCopyMatchesNativeSignatures(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/stubs/php_quickjs.php');
        if (false === $source) {
            throw new RuntimeException('Cannot read QuickJS stubs');
        }
        $namespace = 'PuphpeteerStubSignatures';
        if (!class_exists($namespace . '\\QuickJS', false)) {
            $source = str_replace(
                ['namespace {', 'namespace Js {'],
                ['namespace ' . $namespace . ' { use \\Exception;', 'namespace ' . $namespace . '\\Js {'],
                $source,
            );
            eval(substr($source, strlen('<?php')));
        }
        /** @var list<class-string> $classes */
        $classes = ['QuickJS', 'Js\\Callback', 'QuickJSException', 'QuickJSEvalException', 'QuickJSTimeoutException', 'QuickJSMemoryException'];
        foreach ($classes as $class) {
            $native = new ReflectionClass($class);
            /** @var class-string $stubClass */
            $stubClass = $namespace . '\\' . $class;
            $stub = new ReflectionClass($stubClass);
            $expected = self::signatures($stub);
            $actual = self::signatures($native);
            self::assertSame($expected, $actual, $class . ' signatures must match the Psalm copy');
        }
    }

    /** @psalm-mutation-free */
    private static function signatures(ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = [
                    $parameter->getName(),
                    (string) ($parameter->getType() ?? 'mixed'),
                    $parameter->isPassedByReference(),
                    $parameter->isVariadic(),
                    $parameter->isOptional(),
                    $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                ];
            }
            $methods[$method->getName()] = [
                $method->isStatic(), $parameters, (string) ($method->getReturnType() ?? 'mixed'),
            ];
        }
        ksort($methods);

        return $methods;
    }
}
