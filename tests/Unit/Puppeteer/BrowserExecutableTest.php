<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Nesk\Puphpeteer\Internal\BrowserExecutable;
use Nesk\Puphpeteer\Internal\BrowserInstallation;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class BrowserExecutableTest extends TestCase
{
    private string $root;
    private string $package;
    /** @var array<string, string|false> */
    private array $environment = [];

    #[Override]
    protected function setUp(): void
    {
        foreach (['PUPPETEER_EXECUTABLE_PATH', 'PUPPETEER_CACHE_DIR', 'PUPPETEER_SKIP_DOWNLOAD', 'PUPPETEER_CHROME_SKIP_DOWNLOAD', 'PUPPETEER_SKIP_CHROME_DOWNLOAD'] as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key);
        }
        $this->root = sys_get_temp_dir() . '/puphpeteer-browser-' . bin2hex(random_bytes(8));
        $this->package = $this->root . '/vendor/zoon/puphpeteer';
        mkdir($this->package . '/upstream', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents($this->package . '/composer.json', '{}');
        file_put_contents($this->package . '/upstream/lock.json', '{"package":{"chromeBuildId":"153.0.8010.36"}}');
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            putenv(false === $value ? $key : $key . '=' . $value);
        }
        ProcessRunner::removeDirectory($this->root);
    }

    public function testExplicitEnvironmentWinsWithoutLockFile(): void
    {
        putenv('PUPPETEER_EXECUTABLE_PATH=/explicit/chrome');
        self::assertSame('/explicit/chrome', BrowserExecutable::resolve('/missing'));
        self::assertTrue(BrowserInstallation::skipDownload());
    }

    public function testApplicationRootAndInstallerUseSamePinnedExecutable(): void
    {
        $installation = new BrowserInstallation($this->package);
        self::assertSame($this->root . '/.chrome', $installation->cache);
        $this->createExecutable($installation->executable());
        self::assertSame($installation->executable(), BrowserExecutable::resolve($this->package));
        self::assertSame($installation->executable(), $installation->install());
    }

    public function testStandalonePackageRoot(): void
    {
        unlink($this->root . '/composer.json');
        self::assertSame($this->package . '/.chrome', (new BrowserInstallation($this->package))->cache);
    }

    public function testRelativeCacheIsRelativeToApplicationNotWorkingDirectory(): void
    {
        putenv('PUPPETEER_CACHE_DIR=custom-cache');
        self::assertSame($this->root . '/custom-cache', (new BrowserInstallation($this->package))->cache);
    }

    public function testAbsoluteCacheUsedByInstallerAndResolver(): void
    {
        putenv('PUPPETEER_CACHE_DIR=' . $this->root . '/shared');
        $installation = new BrowserInstallation($this->package);
        self::assertSame($this->root . '/shared', $installation->cache);
        $this->createExecutable($installation->executable());
        self::assertSame($installation->install(), BrowserExecutable::resolve($this->package));
    }

    public function testDoesNotChooseAnotherVersion(): void
    {
        $installation = new BrowserInstallation($this->package);
        $this->createExecutable(str_replace('153.0.8010.36', '152.0.0.1', $installation->executable()));
        $this->expectException(RuntimeException::class);
        BrowserExecutable::resolve($this->package);
    }

    public function testSkipFlagsDoNotCreateCache(): void
    {
        foreach (['PUPPETEER_SKIP_DOWNLOAD', 'PUPPETEER_CHROME_SKIP_DOWNLOAD', 'PUPPETEER_SKIP_CHROME_DOWNLOAD'] as $key) {
            putenv($key . '=TRUE');
            self::assertNull((new BrowserInstallation($this->package))->install());
            putenv($key . '=false');
            self::assertFalse(BrowserInstallation::skipDownload());
        }
        self::assertDirectoryDoesNotExist($this->root . '/.chrome');
    }

    public function testPlatforms(): void
    {
        self::assertSame('linux-arm64', BrowserInstallation::platform('Linux', 'aarch64'));
        self::assertSame('linux64', BrowserInstallation::platform('Linux', 'x86_64'));
        self::assertSame('mac-arm64', BrowserInstallation::platform('Darwin', 'arm64'));
        self::assertSame('mac-x64', BrowserInstallation::platform('Darwin', 'x86_64'));
        self::assertSame('win64', BrowserInstallation::platform('Windows', 'AMD64'));
        self::assertSame('win32', BrowserInstallation::platform('Windows', 'i686'));
        $this->expectException(RuntimeException::class);
        BrowserInstallation::platform('Linux', 'riscv64');
    }

    public function testArchivePreservesExecutableAndSymlink(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('Fixture creation requires ext-zip');
        }
        $installation = new BrowserInstallation($this->package);
        $destination = $this->root . '/unpacked';
        $entry = substr($installation->executable($destination), strlen($destination) + 1);
        $archive = $this->root . '/fixture.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        $zip->addFromString($entry, '#!/bin/sh');
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFromString('executable-link', $entry);
        $zip->setExternalAttributesName('executable-link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $installation->extract($archive, $destination);
        self::assertTrue(is_executable($installation->executable($destination)));
        self::assertTrue(is_link($destination . '/executable-link'));
        self::assertSame($entry, readlink($destination . '/executable-link'));
    }

    public function testCorruptArchiveFails(): void
    {
        $archive = $this->root . '/bad.zip';
        file_put_contents($archive, 'incomplete download');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chrome extraction failed');
        (new BrowserInstallation($this->package))->extract($archive, $this->root . '/unpacked');
    }

    private function createExecutable(string $path): void
    {
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, '#!/bin/sh');
        chmod($path, 0755);
    }
}
