<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Internal;

use InvalidArgumentException;
use Nesk\Puphpeteer\Internal\NpmModuleHost;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NpmModuleHostTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $directories = [];

    #[Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/puphpeteer-npm-host-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $canonical = realpath($this->root);
        self::assertIsString($canonical);
        $this->root = $canonical;
        $this->directories[] = $this->root;
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach (array_reverse($this->files) as $file) {
            unlink($file);
        }
        foreach (array_reverse($this->directories) as $directory) {
            rmdir($directory);
        }
    }

    public function testResolvesEntryRelativeFilesPackagesAndNestedDependencies(): void
    {
        $entry = $this->write('entry.cjs', "module.exports = require('outer');");
        $relative = $this->write('local.json', '{"ok":true}');
        $main = $this->write('node_modules/outer/lib/main.cjs', 'outer');
        $this->write('node_modules/outer/package.json', '{"main":"lib/main.cjs"}');
        $nested = $this->write('node_modules/outer/node_modules/inner/index.js', 'inner');
        $rootInner = $this->write('node_modules/inner/index.js', 'root inner');
        $host = new NpmModuleHost($this->root);

        self::assertSame($this->root, $host->root());
        self::assertSame($entry, $host->resolve($entry));
        self::assertSame($relative, $host->resolve('./local', $entry));
        self::assertSame($main, $host->resolve('outer', $entry));
        self::assertSame($nested, $host->resolve('inner', $main));
        self::assertSame($rootInner, $host->resolve('inner', $entry));
        self::assertSame('outer', $host->readSource($main));
        self::assertSame("\xffouter", $host->readBinary($main));
        self::assertTrue($host->exists($main));
        self::assertFalse($host->exists($this->root . '/missing'));
    }

    public function testConditionalExportsAndUnavailableSubpath(): void
    {
        $entry = $this->write('entry.js', '');
        $required = $this->write('node_modules/@scope/pkg/require.cjs', 'require');
        $subpath = $this->write('node_modules/@scope/pkg/sub.js', 'sub');
        $this->write('node_modules/@scope/pkg/package.json', json_encode([
            'exports' => [
                '.' => ['import' => './esm.js', 'require' => './require.cjs'],
                './sub' => './sub.js',
            ],
        ], JSON_THROW_ON_ERROR));
        $host = new NpmModuleHost($this->root);

        self::assertSame($required, $host->resolve('@scope/pkg', $entry));
        self::assertSame($subpath, $host->resolve('@scope/pkg/sub', $entry));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package export is unavailable');
        $host->resolve('@scope/pkg/private', $entry);
    }

    public function testFileExtensionWinsOverDirectoryWithTheSameName(): void
    {
        $entry = $this->write('entry.js', '');
        $file = $this->write('predicates.js', 'file');
        $this->write('predicates/other.js', 'directory');

        self::assertSame($file, (new NpmModuleHost($this->root))->resolve('./predicates', $entry));
    }

    public function testRejectsTraversalAndAbsoluteChildImports(): void
    {
        $entry = $this->write('entry.js', '');
        $host = new NpmModuleHost($this->root);
        foreach (['../outside.js', '/etc/passwd'] as $specifier) {
            try {
                $host->resolve($specifier, $entry);
                self::fail("Expected rejection of $specifier");
            } catch (InvalidArgumentException|RuntimeException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $host->resolve('pkg/../other', $entry);
    }

    public function testRejectsSymlinkEscapeForReadsAndResolution(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable');
        }
        $outside = tempnam(sys_get_temp_dir(), 'puphpeteer-outside-');
        self::assertIsString($outside);
        $link = $this->root . '/escape.js';
        try {
            if (!@symlink($outside, $link)) {
                self::markTestSkipped('Symlinks are unavailable');
            }
            $this->files[] = $link;
            $host = new NpmModuleHost($this->root);
            foreach ([$link, $outside] as $path) {
                try {
                    $host->readSource($path);
                    self::fail("Expected rejection of $path");
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('outside application root', $error->getMessage());
                }
                try {
                    $host->readBinary($path);
                    self::fail("Expected rejection of $path");
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('outside application root', $error->getMessage());
                }
                try {
                    $host->exists($path);
                    self::fail("Expected rejection of $path");
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('outside application root', $error->getMessage());
                }
            }
            $this->expectException(RuntimeException::class);
            $host->resolve('./escape.js', $this->write('entry.js', ''));
        } finally {
            unlink($outside);
        }
    }

    public function testRawDeflateKeepsBinaryDataAndHonorsLimit(): void
    {
        $host = new NpmModuleHost($this->root);
        $data = "\x00\xff" . str_repeat('a', 1024);
        $compressed = $host->deflateRaw($data);
        self::assertSame("\xff" . $data, $host->inflateRaw(substr($compressed, 1)));
        $this->expectException(RuntimeException::class);
        $host->inflateRaw(substr($compressed, 1), 10);
    }

    public function testUtf8DecoderPreservesValidTextAndRejectsInvalidBytes(): void
    {
        $host = new NpmModuleHost($this->root);
        self::assertSame("Привет\0😀", $host->decodeUtf8("Привет\0😀"));

        $this->expectException(InvalidArgumentException::class);
        $host->decodeUtf8("\xc3\x28");
    }

    public function testCrc32MatchesNodeIncludingPreviousChecksum(): void
    {
        $host = new NpmModuleHost($this->root);
        self::assertSame(0, $host->crc32(''));
        self::assertSame(907060870, $host->crc32('hello'));
        self::assertSame(4192936109, $host->crc32('world', 907060870));
        self::assertSame(crc32("\x00\x01\xff") & 0xFFFFFFFF, $host->crc32("\x00\x01\xff"));
    }

    public function testRejectsPackageSymlinkOutsideRoot(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable');
        }
        $entry = $this->write('entry.js', '');
        $this->write('node_modules/.keep', '');
        $link = $this->root . '/node_modules/escaped';
        if (!@symlink(sys_get_temp_dir(), $link)) {
            self::markTestSkipped('Symlinks are unavailable');
        }
        $this->files[] = $link;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside application root');
        (new NpmModuleHost($this->root))->resolve('escaped', $entry);
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);
        $missing = [];
        for ($current = $directory; !is_dir($current); $current = dirname($current)) {
            $missing[] = $current;
        }
        foreach (array_reverse($missing) as $newDirectory) {
            mkdir($newDirectory);
            $this->directories[] = $newDirectory;
        }
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
