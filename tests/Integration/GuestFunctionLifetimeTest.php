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
        $marker = '/globalThis\\.__quickjsDispatch\\s*=\\s*/';
        self::assertSame(1, preg_match($marker, $source), 'Guest instrumentation point must be unique');
        $identifier = '[A-Za-z_$][A-Za-z0-9_$]*';
        $constructor = '(?:new Map\\(\\)|new Map|new WeakMap\\(\\)|new WeakMap|new Set\\(\\)|new Set)';
        $separator = '\\s*(?:,|;\\s*(?:var\\s+)?)\\s*';
        $maps = '~var\\s+(?<objects>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<identities>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<nextObject>' . $identifier . ')\\s*=\\s*0' . $separator
            . '(?<nextCallback>' . $identifier . ')\\s*=\\s*0' . $separator
            . '(?<callbacks>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<decodedFunctions>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<pinnedCallbacks>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<eventCallbacks>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?' . $constructor . $separator
            . '(?<eventListeners>' . $identifier . ')\\s*=\\s*(?:/\\*.*?\\*/\\s*)?new Map(?:\\(\\))?\\s*;~s';
        self::assertSame(1, preg_match($maps, $source, $match), 'Guest cache declarations must be discoverable');
        $source = preg_replace_callback($maps, static fn(array $match): string => $match[0]
            . 'globalThis.__quickjsTestObjects=' . $match['objects'] . ';'
            . 'globalThis.__quickjsTestDecodedFunctions=' . $match['decodedFunctions'] . ';', $source, 1);
        self::assertIsString($source);
        // Observe the real cache and provide a receiver without starting Chrome.
        // Decoding, dispatch and releaseFunction remain the shipped bundle's code.
        $source = preg_replace($marker, <<<'JS'
        globalThis.__functionCacheSize = () => globalThis.__quickjsTestDecodedFunctions.size;
        globalThis.__quickjsTestObjects.set(-1, {retain(fn) { globalThis.__retainedFunction = fn; return fn(); }});
        globalThis.__quickjsDispatch =
        JS, $source, 1);
        self::assertIsString($source);
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
