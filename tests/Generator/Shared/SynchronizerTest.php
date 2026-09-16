<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Generator\Shared;

use Nesk\Puphpeteer\Console\Command\Generate\CodeStyle;
use Nesk\Puphpeteer\Console\Command\Generate\Synchronizer;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** @psalm-import-type ClassSpec from Synchronizer */
final class SynchronizerTest extends TestCase
{
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/puphpeteer-generator-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0700, true);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    /**
     * @param list<ClassSpec> $classes
     *
     * @return array{diagnostics:list<array>,files:list<array{path:string,content:string,changed:bool}>,deletedFiles:list<string>}
     */
    private function synchronize(array $classes): array
    {
        $result = (new Synchronizer())->synchronize($this->root, $classes);
        $formatted = CodeStyle::formatMany(array_column($result['files'], 'content'));
        foreach ($formatted as $index => $content) {
            $result['files'][$index]['content'] = $content;
        }

        $result['files'] = array_values($result['files']);

        return $result;
    }

    /** @return ClassSpec
     * @psalm-pure
     */
    private function fixture(): array
    {
        return ['name' => 'GeneratedFixture', 'fqcn' => 'Nesk\\Puphpeteer\\Tests\\Generator\\Shared\\GeneratedFixture', 'file' => 'src/GeneratedFixture.php', 'members' => [
            ['id' => 'GeneratedFixture.select', 'name' => 'select', 'jsName' => '$', 'kind' => 'method', 'parameters' => [
                ['name' => 'selector', 'type' => 'string', 'docType' => 'string'],
                ['name' => 'options', 'type' => '?array', 'docType' => 'array{timeout?: int}|null', 'optional' => true, 'default' => null],
            ], 'returnType' => 'string', 'returnDocType' => 'string'],
            ['id' => 'GeneratedFixture.evaluate', 'name' => 'evaluate', 'kind' => 'method', 'parameters' => [
                ['name' => 'expression', 'type' => 'string'],
                ['name' => 'arguments', 'type' => 'mixed', 'variadic' => true],
            ], 'returnType' => 'mixed', 'returnDocType' => 'mixed'],
        ]];
    }

    public function testEmitsExecutableTypedBridgeWithOptionalAndVariadicArguments(): void
    {
        $result = $this->synchronize([$this->fixture()]);
        self::assertSame([], $result['diagnostics']);
        $content = $result['files'][0]['content'];
        self::assertSame(file_get_contents(__DIR__ . '/Fixtures/GeneratedFixture.php'), $content);
        self::assertMatchesRegularExpression('/@param array\\{timeout\\?: int\\}\\|null\\s+\\$options/', $content);
        self::assertStringContainsString('public function select(string $selector, ?array $options = null): string', $content);
        self::assertStringContainsString('extends RemoteObject', $content);
        self::assertStringNotContainsString('upstream-id:', $content);
        self::assertStringNotContainsString('generated-bridge-sha256', $content);
        self::assertStringNotContainsString('$_remoteResult', $content);
        file_put_contents($this->root . '/src/GeneratedFixture.php', $content);
        require $this->root . '/src/GeneratedFixture.php';
        // Override only the transport, so assertions execute the emitted method bodies.
        $object = new class extends GeneratedFixture {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $calls = [];

            /** @psalm-mutation-free */
            public function __construct()
            {
            }

            /** @psalm-external-mutation-free */
            #[Override]
            protected function invokeRemote(string $method, array $arguments): mixed
            {
                $this->calls[] = [$method, $arguments];

                return 'result';
            }
        };
        self::assertSame('result', $object->select('a'));
        $object->select('b', null);
        $object->select(options: ['timeout' => 10], selector: 'c');
        $object->evaluate('x', 1, 'two');
        $object->evaluate('y', first: 3, second: 4);
        $object->evaluate('z', 5, extra: 6);
        $object->evaluate(expression: 'empty');
        self::assertSame([
            ['$', ['a']], ['$', ['b', null]], ['$', ['c', ['timeout' => 10]]],
            ['evaluate', ['x', 1, 'two']], ['evaluate', ['y', 3, 4]],
            ['evaluate', ['z', 5, 6]], ['evaluate', ['empty']],
        ], $object->calls);
        $repeat = $this->synchronize([$this->fixture()]);
        self::assertSame([], $repeat['diagnostics']);
        self::assertSame($content, $repeat['files'][0]['content']);
    }

    public function testWritableHooksAndStaticRoutingExecuteGeneratedCode(): void
    {
        $spec = ['name' => 'MutableFixture', 'fqcn' => __NAMESPACE__ . '\\MutableFixture', 'file' => 'src/MutableFixture.php', 'members' => [
            ['id' => 'MutableFixture.value', 'name' => 'value', 'kind' => 'property', 'type' => 'int', 'writable' => true],
            ['id' => 'MutableFixture::size', 'name' => 'size', 'kind' => 'method', 'remoteStatic' => true, 'returnType' => 'int'],
        ]];
        $result = $this->synchronize([$spec]);
        self::assertSame([], $result['diagnostics']);
        file_put_contents($this->root . '/src/MutableFixture.php', $result['files'][0]['content']);
        require $this->root . '/src/MutableFixture.php';
        $object = new class extends MutableFixture {
            private int $stored = 1;

            /** @psalm-mutation-free */
            public function __construct()
            {
            }

            /** @psalm-mutation-free */
            #[Override]
            protected function getRemote(string $name): mixed
            {
                return $this->stored;
            }

            /** @psalm-external-mutation-free */
            #[Override]
            protected function setRemote(string $name, mixed $value): void
            {
                $this->stored = $value;
            }

            /** @psalm-pure */
            #[Override]
            protected function invokeStaticRemote(string $method, array $arguments): mixed
            {
                return 42;
            }
        };
        self::assertSame(1, $object->value);
        $object->value = 7;
        self::assertSame(7, (new ReflectionProperty($object, 'value'))->getValue($object));
        self::assertSame(42, $object->size());
    }

    public function testRegeneratesWholeClassIncludingManualEdits(): void
    {
        $spec = $this->fixture();
        $first = $this->synchronize([$spec]);
        $path = $this->root . '/src/GeneratedFixture.php';
        file_put_contents($path, $first['files'][0]['content']);
        $spec['members'][0]['jsName'] = 'newSelector';
        $second = $this->synchronize([$spec]);
        self::assertSame([], $second['diagnostics']);
        self::assertStringContainsString("invokeRemote('newSelector'", $second['files'][0]['content']);
        // Synchronization only proposes changes and must not mutate source files.
        self::assertSame($first['files'][0]['content'], file_get_contents($path));
        $manual = str_replace("invokeRemote('$'", "invokeRemote('custom'", $first['files'][0]['content']);
        file_put_contents($path, $manual);
        $third = $this->synchronize([$spec]);
        self::assertSame([], $third['diagnostics']);
        self::assertStringNotContainsString("invokeRemote('custom'", $third['files'][0]['content']);
        self::assertStringContainsString("invokeRemote('newSelector'", $third['files'][0]['content']);
    }

    public function testInheritedMethodsReceiveOverrideAndDocsPreserveEscapes(): void
    {
        $parent = $this->fixture();
        $child = $parent;
        $child['name'] = 'ChildFixture';
        $child['fqcn'] = 'Nesk\\Puphpeteer\\Tests\\Generator\\Shared\\ChildFixture';
        $child['file'] = 'src/ChildFixture.php';
        $child['extends'] = '\\' . $parent['fqcn'];
        $literal = "'\\\\'";
        $child['members'][0]['parameters'][0]['docType'] = $literal;
        $result = $this->synchronize([$parent, $child]);
        self::assertSame([], $result['diagnostics']);
        $content = $result['files'][0]['content'];
        self::assertStringContainsString('#[Override]', $content);
        self::assertMatchesRegularExpression('/@param ' . preg_quote($literal, '/') . '\\s+\\$selector/', $content);
    }

    public function testReportsUnsupportedAndDeletedDeclarations(): void
    {
        $spec = $this->fixture();
        $first = $this->synchronize([$spec]);
        file_put_contents($this->root . '/src/GeneratedFixture.php', $first['files'][0]['content']);
        $spec['members'] = [['id' => 'GeneratedFixture.static', 'name' => 'create', 'kind' => 'method', 'static' => true]];
        $result = $this->synchronize([$spec]);
        self::assertContains('contract.unsupported', array_column($result['diagnostics'], 'code'));
        self::assertStringNotContainsString('function create', $result['files'][0]['content']);
        self::assertStringNotContainsString('function select', $result['files'][0]['content']);
    }
}
