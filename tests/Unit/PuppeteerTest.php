<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Unit;
use Nesk\Puphpeteer\Puppeteer;
use PHPUnit\Framework\TestCase;

final class PuppeteerTest extends TestCase
{
    public function testUnsupportedOldOptionsAreRejectedBeforeStartingRuntime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('js_extra');
        (new \ReflectionClass(Puppeteer::class))->newInstance(['js_extra'=>'require("puppeteer-extra")']);
    }

    public function testConnectRequiresExactlyOneEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Puppeteer())->connect(['browserWSEndpoint'=>'ws://localhost', 'browserURL'=>'http://localhost']);
    }

    public function testLaunchRejectsUnsupportedOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pipe');
        (new \ReflectionMethod(Puppeteer::class, 'launch'))->invoke(new Puppeteer(), ['pipe'=>true]);
    }

    public function testChromeArgumentsUseUpstreamDefaults(): void
    {
        $puppeteer = new Puppeteer();
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/launch-defaults.json');
        self::assertIsString($source);
        $defaults = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $arguments = $defaults['true:false'];
        self::assertIsArray($arguments);
        self::assertSame([...$arguments, 'about:blank'], $puppeteer->defaultArgs());
        self::assertSame(['--custom', 'about:blank'], $puppeteer->defaultArgs(['ignoreDefaultArgs'=>true, 'args'=>['--custom']]));
        self::assertNotContains('--no-first-run', $puppeteer->defaultArgs(['ignoreDefaultArgs'=>['--no-first-run']]));
    }
}
