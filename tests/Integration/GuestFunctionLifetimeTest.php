<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class GuestFunctionLifetimeTest extends TestCase
{
    public function testRealGuestReleasesCacheWithoutInvalidatingRetainedFunction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/puppeteer.js');
        if ($source === false) { throw new \RuntimeException('Missing bundle'); }
        $marker = 'globalThis.__quickjsDispatch =';
        self::assertSame(1, substr_count($source, $marker), 'Guest instrumentation point must be unique');
        // Observe the real cache and provide a receiver without starting Chrome.
        // Decoding, dispatch and releaseFunction remain the shipped bundle's code.
        $source = str_replace($marker, <<<'JS'
        globalThis.__functionCacheSize = () => decodedFunctions.size;
        objects.set(-1, {retain(fn) { globalThis.__retainedFunction = fn; return fn(); }});
        globalThis.__quickjsDispatch =
        JS, $source);
        $js = new \QuickJS();
        $js->register('now', static fn(): float => 0.0);
        $js->eval($source);
        $dispatch = $js->eval('globalThis.__quickjsDispatch');
        self::assertInstanceOf(\Js\Callback::class, $dispatch);
        $request = ['id' => 1, 'object' => -1, 'method' => 'retain', 'args' => [
            ['$quickjs' => 'function', 'id' => 7, 'source' => '() => 42'],
        ]];
        $first = $dispatch->dispatch(['call', $request], 100);
        self::assertSame([['result', ['id' => 1, 'value' => 42]]], $first['messages']);
        self::assertSame(1, $js->eval('__functionCacheSize()'));
        $request['id'] = 2;
        $request['args'][0]['source'] = '() => 99';
        $second = $dispatch->dispatch(['call', $request], 100);
        self::assertSame([['result', ['id' => 2, 'value' => 42]]], $second['messages'], 'Live identity must be reused');
        $dispatch->dispatch(['releaseFunction', ['id' => 7]], 100);
        self::assertSame(0, $js->eval('__functionCacheSize()'));
        self::assertSame(42, $js->eval('__retainedFunction()'), 'Listener-held function must remain callable');
    }
}
