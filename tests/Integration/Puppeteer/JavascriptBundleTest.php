<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\Client;
use PHPUnit\Framework\TestCase;
use QuickJSEvalException;
use RuntimeException;

final class JavascriptBundleTest extends TestCase
{
    public function testBundleErrorsKeepJavascriptCoordinates(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'quickjs-bundle-');
        if (false === $bundle) {
            throw new RuntimeException('Cannot create fixture');
        }
        file_put_contents($bundle, "\n\nthrow new Error('bundle failure');");
        try {
            new Client($bundle);
            self::fail('Expected bundle failure');
        } catch (QuickJSEvalException $error) {
            self::assertSame('bundle failure', $error->getMessage());
            self::assertSame('guest.js', $error->getFile());
            self::assertSame(3, $error->getLine());
        } finally {
            unlink($bundle);
        }
    }
}
