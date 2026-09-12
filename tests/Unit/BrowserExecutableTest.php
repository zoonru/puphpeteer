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
        file_put_contents($this->root . '/vendor/zoon/puphpeteer/resources/manifest.json', '{"puppeteer":"24.36.1","chrome":"144.0.7559.96"}');
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

    public function testWrongRevisionDoesNotFallBackToSystemChrome(): void
    {
        $this->install('999.0.0.0');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Compatible Chrome not found in node_modules');
        BrowserExecutable::resolve($this->root . '/vendor/zoon/puphpeteer');
    }

    public function testManifestCannotResolveOutsideNodeModulesCache(): void
    {
        $this->install('144.0.7559.96');
        $path = $this->root . '/node_modules/.puphpeteer/chrome.json';
        file_put_contents($path, json_encode(['puppeteer'=>'24.36.1', 'buildId'=>'144.0.7559.96', 'executable'=> '../../../' . basename($this->root) . '/external'], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/external', '#!/bin/sh');
        chmod($this->root . '/external', 0755);
        $this->expectException(\RuntimeException::class);
        BrowserExecutable::resolve($this->root . '/vendor/zoon/puphpeteer');
    }

    private function install(string $revision): string
    {
        $cache = $this->root . '/node_modules/.puphpeteer';
        mkdir($cache, 0777, true);
        file_put_contents($cache . '/chrome', '#!/bin/sh');
        chmod($cache . '/chrome', 0755);
        file_put_contents($cache . '/chrome.json', json_encode(['puppeteer'=>'24.36.1', 'buildId'=>$revision, 'executable'=>'chrome'], JSON_THROW_ON_ERROR));
        return $cache . '/chrome';
    }
}
