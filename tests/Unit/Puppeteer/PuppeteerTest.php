<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use InvalidArgumentException;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PuppeteerTest extends TestCase
{
    public function testUnsupportedOldOptionsAreRejectedBeforeStartingRuntime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('js_extra');
        (new ReflectionClass(Puppeteer::class))->newInstance(['js_extra' => 'require("puppeteer-extra")']);
    }

    public function testConnectRequiresExactlyOneEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Puppeteer())->connect(['browserWSEndpoint' => 'ws://localhost', 'browserURL' => 'http://localhost']);
    }

    public function testLaunchRejectsUnsupportedOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pipe');
        (new ReflectionMethod(Puppeteer::class, 'launch'))->invoke(new Puppeteer(), ['pipe' => true]);
    }

    public function testChromeArgumentsUseUpstreamDefaults(): void
    {
        $puppeteer = new Puppeteer();
        self::assertSame(['--custom', 'about:blank'], $puppeteer->defaultArgs(['ignoreDefaultArgs' => true, 'args' => ['--custom']]));
        self::assertNotContains('--no-first-run', $puppeteer->defaultArgs(['ignoreDefaultArgs' => ['--no-first-run']]));
    }

    public function testNoPluginsUseTheCoreBundleByDefault(): void
    {
        $bundle = (new ReflectionMethod(Puppeteer::class, 'bundle'))->invoke(new Puppeteer());
        self::assertSame(dirname(__DIR__, 3) . '/resources/puppeteer-core.js', $bundle);
    }

    public function testPluginsUseTheFullBundleByDefault(): void
    {
        $puppeteer = (new Puppeteer())->use('stealth');
        $bundle = (new ReflectionMethod(Puppeteer::class, 'bundle'))->invoke($puppeteer);
        self::assertSame(dirname(__DIR__, 3) . '/resources/puppeteer.js', $bundle);
    }

    public function testExplicitBundleTakesPrecedenceOverAutomaticSelection(): void
    {
        $path = '/tmp/custom-puppeteer.js';
        $bundle = (new ReflectionMethod(Puppeteer::class, 'bundle'))->invoke(new Puppeteer(['bundle' => $path]));
        self::assertSame($path, $bundle);
    }
}
