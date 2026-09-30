<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsFunctionHandle;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use PHPUnit\Framework\TestCase;

final class NpmRuntimeTest extends TestCase
{
    public function testBufferUtf8DecodingUsesTheHostAndRejectsInvalidBytes(): void
    {
        $runtime = new JsRuntime(moduleRoot: dirname(__DIR__, 3));
        $buffer = $runtime->require('node:buffer')['Buffer'];

        self::assertInstanceOf(JsFunctionHandle::class, $buffer);
        self::assertSame('Привет', $runtime->run(new JsFunction('(Buffer) => Buffer.from("Привет").toString()'), $buffer));
        self::assertTrue($runtime->run(new JsFunction('(Buffer) => {
            try { Buffer.from([0xc3, 0x28]).toString(); return false; }
            catch (error) { return error.message.includes("Invalid UTF-8 data"); }
        }'), $buffer));
    }

    public function testPackagesAndCommonJsBundlesLoadIntoThePuppeteerRuntime(): void
    {
        $root = dirname(__DIR__, 3);
        $runtime = new JsRuntime(moduleRoot: $root);
        $stealth = $runtime->require('puppeteer-extra-plugin-stealth');
        $fingerprint = $runtime->require('fingerprint-injector');
        $exports = $runtime->require($root . '/tests/Fixtures/npm-entry.cjs');

        self::assertInstanceOf(JsFunctionHandle::class, $stealth);
        self::assertInstanceOf(JsFunctionHandle::class, $fingerprint['newInjectedPage']);
        self::assertInstanceOf(JsFunctionHandle::class, $exports['twice']);
        self::assertSame(42, $exports['twice'](21));
        self::assertSame(42, $runtime->run(new JsFunction('(twice, value) => twice(value)'), $exports['twice'], 21));
        self::assertSame(42, $runtime->run(new JsFunction('async callback => await callback(21)'), static fn (int $value): int => $value * 2));
        self::assertInstanceOf(JsFunctionHandle::class, $exports['stealth']);
        self::assertInstanceOf(JsFunctionHandle::class, $exports['newInjectedPage']);
        self::assertStringContainsString('Chrome/', $exports['fingerprintUserAgent']());

        (new Puppeteer(runtime: $runtime))->use($stealth());
    }
}
