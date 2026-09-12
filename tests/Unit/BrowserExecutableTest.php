<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit;

use Nesk\Puphpeteer\Internal\BrowserExecutable;
use Nesk\Puphpeteer\Tests\Support\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class BrowserExecutableTest extends TestCase
{
    private string $root;
    private string|false $configured;
    private string|false $legacy;

    #[\Override]
    protected function setUp(): void
    {
        $this->configured = getenv('PUPPETEER_EXECUTABLE_PATH');
        $this->legacy = getenv('CHROME_BIN');
        putenv('PUPPETEER_EXECUTABLE_PATH');
        putenv('CHROME_BIN');
        $this->root = sys_get_temp_dir() . '/puphpeteer-browser-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/vendor/zoon/puphpeteer/resources', 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->configured === false ? 'PUPPETEER_EXECUTABLE_PATH' : 'PUPPETEER_EXECUTABLE_PATH=' . $this->configured);
        putenv($this->legacy === false ? 'CHROME_BIN' : 'CHROME_BIN=' . $this->legacy);
        ProcessRunner::removeDirectory($this->root);
    }

    public function testExplicitEnvironmentWins(): void
    {
        putenv('CHROME_BIN=/explicit/legacy/chrome');
        self::assertSame('/explicit/legacy/chrome', BrowserExecutable::resolve($this->root));
        putenv('PUPPETEER_EXECUTABLE_PATH=/explicit/chrome');
        self::assertSame('/explicit/chrome', BrowserExecutable::resolve($this->root));
    }

    public function testFindsApplicationInstallationAboveComposerPackage(): void
    {
        $executable = $this->install('144.0.7559.96');
        self::assertSame(realpath($executable), BrowserExecutable::resolve($this->root . '/vendor/zoon/puphpeteer'));
    }

    public function testFindsPuppeteerBrowsersCacheLayout(): void
    {
        $executable = $this->root . '/node_modules/.puphpeteer/chrome/mac_arm-144.0.7559.96/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
        mkdir(dirname($executable), 0777, true);
        file_put_contents($executable, '#!/bin/sh');
        chmod($executable, 0755);
        self::assertSame(realpath($executable), BrowserExecutable::resolve($this->root . '/vendor/zoon/puphpeteer'));
    }

    public function testDoesNotResolveExecutableOutsideCache(): void
    {
        $cache = $this->root . '/node_modules/.puphpeteer';
        $installation = $cache . '/chrome/mac_arm-144.0.7559.96';
        mkdir($installation . '/chrome-mac-arm64', 0777, true);
        file_put_contents($this->root . '/external', '#!/bin/sh');
        chmod($this->root . '/external', 0755);
        symlink($this->root . '/external', $installation . '/chrome-mac-arm64/chrome');
        $this->expectException(\RuntimeException::class);
        BrowserExecutable::resolve($this->root . '/vendor/zoon/puphpeteer');
    }

    private function install(string $revision): string
    {
        return $this->installAt($this->root . '/node_modules/.puphpeteer', $revision);
    }

    private function installAt(string $cache, string $revision): string
    {
        $executable = $cache . '/chrome/mac_arm-' . $revision . '/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing';
        mkdir(dirname($executable), 0777, true);
        file_put_contents($executable, '#!/bin/sh');
        file_put_contents($cache . '/chrome/.metadata', json_encode(['aliases' => ['pinned' => $revision]], JSON_THROW_ON_ERROR));
        chmod($executable, 0755);
        return $executable;
    }
}
