<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Zoon\Puphpeteer\Tooling\Request;
use Zoon\Puphpeteer\Tooling\Synchronizer;

final class SynchronizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/puphpeteer-php-tooling-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function specification(): array
    {
        return [['name' => 'Browser', 'fqcn' => 'Fixture\\Browser', 'file' => 'src/Browser.php', 'members' => [
            ['id' => 'Browser.count', 'name' => 'count', 'kind' => 'method', 'parameters' => [['name' => 'value', 'type' => 'int', 'docType' => 'int']], 'returnType' => 'int'],
        ]]];
    }

    private function generate(array $spec): array
    {
        return (new Synchronizer())->synchronize($this->root, $spec, ['Browser.count', 'Browser.name']);
    }

    private function publish(array $result): void
    {
        foreach ($result['files'] as $file) {
            file_put_contents($this->root . '/' . $file['path'], $file['content']);
        }
    }

    public function testGenerationIsAProposalAndSecondRunIsByteIdentical(): void
    {
        $result = $this->generate($this->specification());
        self::assertFileDoesNotExist($this->root . '/src/Browser.php');
        self::assertStringContainsString('NotImplemented: Browser.count', $result['files'][0]['content']);
        $this->publish($result);
        $second = $this->generate($this->specification());
        self::assertSame($result['files'][0]['content'], $second['files'][0]['content']);
        self::assertFalse($second['files'][0]['changed']);
    }

    public function testUpdatesOnlyDeclarationsPreservingBodiesCommentsAndHelpers(): void
    {
        $spec = $this->specification();
        $result = $this->generate($spec);
        $code = $result['files'][0]['content'];
        $code = str_replace("throw new \\LogicException('NotImplemented: Browser.count');", '// manual body comment' . "\n        return \$value + 1;", $code);
        $code = str_replace(' * [upstream-generated]', ' * Manual documentation.' . "\n * [upstream-generated]", $code);
        $code = substr($code, 0, strrpos($code, '}')) . "    private function helper(): string { return 'keep'; }\n}\n";
        file_put_contents($this->root . '/src/Browser.php', $code);
        $spec[0]['members'][0]['parameters'][0]['type'] = 'int|float';
        $spec[0]['members'][0]['parameters'][0]['docType'] = 'int|float';
        $spec[0]['members'][0]['returnType'] = 'int|float';
        $spec[0]['members'][] = ['id' => 'Browser.name', 'name' => 'name', 'kind' => 'method', 'returnType' => 'string'];
        $updated = $this->generate($spec)['files'][0]['content'];
        self::assertStringContainsString('function count(int|float $value): int|float', $updated);
        self::assertStringContainsString('// manual body comment', $updated);
        self::assertStringContainsString('return $value + 1;', $updated);
        self::assertStringContainsString('Manual documentation.', $updated);
        self::assertStringContainsString("private function helper(): string { return 'keep'; }", $updated);
        self::assertStringContainsString('function name(): string', $updated);
    }

    public function testPropertyInitializerRemainsIntact(): void
    {
        $spec = $this->specification();
        $spec[0]['members'] = [['id' => 'Browser.count', 'name' => 'count', 'kind' => 'property', 'type' => 'int']];
        $result = $this->generate($spec);
        $code = str_replace('$count;', '$count = 7;', $result['files'][0]['content']);
        file_put_contents($this->root . '/src/Browser.php', $code);
        $spec[0]['members'][0]['type'] = 'int|float';
        self::assertStringContainsString('int|float $count = 7;', $this->generate($spec)['files'][0]['content']);
    }

    public function testRemovedDeclarationsAreReportedAndPreserved(): void
    {
        $this->publish($this->generate($this->specification()));
        $before = file_get_contents($this->root . '/src/Browser.php');
        $sync = new Synchronizer();
        $obsolete = $sync->synchronize($this->root, [], []);
        self::assertContains('contract.obsolete', array_column($obsolete['diagnostics'], 'code'));
        self::assertSame($before, file_get_contents($this->root . '/src/Browser.php'));
        self::assertSame([], $sync->synchronize($this->root, [], ['Browser.count'])['diagnostics']);
    }

    public function testUntrackedCollisionIsNeverOverwritten(): void
    {
        $source = '<?php namespace Fixture; class Browser { public function count(string $other): string { return $other; } }';
        file_put_contents($this->root . '/src/Browser.php', $source);
        $result = $this->generate($this->specification());
        self::assertContains('contract.conflict', array_column($result['diagnostics'], 'code'));
        self::assertSame($source, $result['files'][0]['content']);
    }

    public function testInvalidJsonAndPathAreRejected(): void
    {
        $request = Request::decode(json_encode(['root' => $this->root, 'classes' => $this->specification()], JSON_THROW_ON_ERROR));
        self::assertSame($this->root, $request['root']);
        $spec = $this->specification();
        $spec[0]['file'] = '../outside.php';
        $this->expectException(RuntimeException::class);
        $this->generate($spec);
    }

    public function testOptionalParameterRequiresExplicitDefault(): void
    {
        $spec = $this->specification();
        $spec[0]['members'][0]['parameters'][0]['optional'] = true;
        self::assertContains('contract.unsupported', array_column($this->generate($spec)['diagnostics'], 'code'));
    }
    public function testInheritedPublicMemberAndImportsSatisfyContractWithoutOverride(): void
    {
        $spec = $this->specification();
        $spec[0]['members'][0]['parameters'] = [['name' => 'value', 'type' => '\\DateTimeImmutable']];
        $spec[0]['members'][0]['returnType'] = '\\DateTimeImmutable';
        file_put_contents($this->root . '/src/Base.php', '<?php namespace Fixture; use DateTimeImmutable as Clock; class Base { final public function count(Clock $value): Clock { return $value; } }');
        $child = '<?php namespace Fixture; use Fixture\\Base as ParentClass; class Browser extends ParentClass {}';
        file_put_contents($this->root . '/src/Browser.php', $child);
        $result = $this->generate($spec);
        self::assertSame([], $result['diagnostics']);
        self::assertSame($child, $result['files'][0]['content']);
        self::assertSame([], (new Synchronizer())->synchronize($this->root, $spec, ['Browser.count'])['diagnostics']);
    }

    public function testInheritedPrivateMemberDoesNotSatisfyPublicContract(): void
    {
        file_put_contents($this->root . '/src/Base.php', '<?php namespace Fixture; class Base { private function count(int $value): int { return $value; } }');
        file_put_contents($this->root . '/src/Browser.php', '<?php namespace Fixture; class Browser extends Base {}');
        $result = $this->generate($this->specification());
        self::assertStringContainsString('public function count(int $value): int', $result['files'][0]['content']);
    }

    public function testFinalFlagIsPreserved(): void
    {
        $spec = $this->specification();
        $first = $this->generate($spec);
        $code = str_replace('public function count', 'final public function count', $first['files'][0]['content']);
        file_put_contents($this->root . '/src/Browser.php', $code);
        $spec[0]['members'][0]['returnType'] = 'int|float';
        $updated = $this->generate($spec);
        self::assertMatchesRegularExpression('/(?:final public|public final) function count/', $updated['files'][0]['content']);
    }

    public function testTemplatesAndDefaultsAreGeneratedAndValidated(): void
    {
        $spec = $this->specification();
        $spec[0]['templates'] = [['name' => 'T', 'constraint' => 'object']];
        $spec[0]['members'][0]['templates'] = [['name' => 'TResult']];
        $spec[0]['members'][0]['parameters'][0]['optional'] = true;
        $spec[0]['members'][0]['parameters'][0]['default'] = 7;
        $result = $this->generate($spec);
        self::assertStringContainsString('@template T as object', $result['files'][0]['content']);
        self::assertStringContainsString('@template TResult', $result['files'][0]['content']);
        self::assertStringContainsString('int $value = 7', $result['files'][0]['content']);
        $this->publish($result);
        $spec[0]['templates'][0]['constraint'] = 'string';
        self::assertStringContainsString('@template T as string', $this->generate($spec)['files'][0]['content']);
    }

    public function testMalformedRequestDoesNotReachAstGeneration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Request::decode('{"root":"/tmp","classes":[{"name":"Browser","fqcn":"Browser","file":"src/B.php","members":[{"id":"B.x","name":"x","kind":"method","parameters":"wrong"}]}]}');
    }

    public function testReadonlyPropertyAndParameterAttributesArePreserved(): void
    {
        $spec = $this->specification();
        $spec[0]['members'][] = ['id' => 'Browser.name', 'name' => 'name', 'kind' => 'property', 'type' => 'string'];
        $result = $this->generate($spec);
        $code = str_replace('public string $name;', 'public readonly string $name;', $result['files'][0]['content']);
        $code = str_replace('int $value', '#[\\SensitiveParameter] int $value', $code);
        file_put_contents($this->root . '/src/Browser.php', $code);
        $spec[0]['members'][0]['parameters'][0]['type'] = 'int|float';
        $spec[0]['members'][1]['type'] = 'string|int';
        $updated = $this->generate($spec)['files'][0]['content'];
        self::assertStringContainsString('#[\\SensitiveParameter]', $updated);
        self::assertStringContainsString('readonly string|int $name;', $updated);
    }

    public function testAmbiguousMemberMappingsDoNotSelectFirstCandidate(): void
    {
        $spec = $this->specification();
        $spec[0]['members'][] = ['id' => 'Browser.other', 'name' => 'COUNT', 'kind' => 'method', 'returnType' => 'string'];
        $result = $this->generate($spec);
        self::assertCount(2, $result['diagnostics']);
        self::assertStringNotContainsString('function count', $result['files'][0]['content']);
        self::assertStringNotContainsString('function COUNT', $result['files'][0]['content']);
    }

    public function testPhpSemanticSyntaxErrorsAreNotPublished(): void
    {
        $spec = $this->specification();
        $spec[0]['members'][0]['parameters'][0]['type'] = 'void';
        $result = $this->generate($spec);
        self::assertSame([], $result['files']);
        self::assertContains('contract.conflict', array_column($result['diagnostics'], 'code'));
        self::assertStringNotContainsString('puphpeteer-lint-', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRemovingTemplatesRemovesOnlyGeneratedAnnotations(): void
    {
        $spec = $this->specification();
        $spec[0]['templates'] = [['name' => 'T']];
        $this->publish($this->generate($spec));
        $spec[0]['templates'] = [];
        $updated = $this->generate($spec);
        self::assertStringNotContainsString('@template T', $updated['files'][0]['content']);
    }

    public function testUnresolvedTraitMethodIsNeverShadowedByGeneratedStub(): void
    {
        $code = '<?php namespace Fixture; trait Behavior { public function count(int $value): int { return $value + 1; } } class Browser { use Behavior; }';
        file_put_contents($this->root . '/src/Browser.php', $code);
        $result = $this->generate($this->specification());
        self::assertContains('contract.conflict', array_column($result['diagnostics'], 'code'));
        self::assertSame($code, $result['files'][0]['content']);
        self::assertSame($code, file_get_contents($this->root . '/src/Browser.php'));
    }

    public function testUnresolvedInheritedTraitAlsoBlocksAnOverride(): void
    {
        file_put_contents($this->root . '/src/Base.php', '<?php namespace Fixture; trait Behavior { public function count(int $value): int { return $value + 1; } } class Base { use Behavior; }');
        $code = '<?php namespace Fixture; class Browser extends Base {}';
        file_put_contents($this->root . '/src/Browser.php', $code);
        $result = $this->generate($this->specification());
        self::assertContains('contract.conflict', array_column($result['diagnostics'], 'code'));
        self::assertSame($code, $result['files'][0]['content']);
    }

}
