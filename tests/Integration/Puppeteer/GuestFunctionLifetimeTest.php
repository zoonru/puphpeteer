<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Amp\Process\Process;
use Js\Callback;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;
use PHPUnit\Framework\TestCase;
use QuickJS;
use RuntimeException;

final class GuestFunctionLifetimeTest extends TestCase
{
    public function testRealGuestReleasesCacheWithoutInvalidatingRetainedFunction(): void
    {
        $root = dirname(__DIR__, 3);
        $build = ProcessRunner::collect(Process::start(['node', 'tools/build.cjs', '--test'], $root), 60);
        self::assertSame(0, $build['code'], $build['stdout'] . $build['stderr']);
        $source = file_get_contents($root . '/.build/puppeteer.test.js');
        if (false === $source) {
            throw new RuntimeException('Missing test bundle');
        }
        $js = new QuickJS();
        $js->register('now', static fn (): float => 0.0);
        $js->eval($source);
        $js->eval(<<<'JS'
        __quickjsTest.registerObject(-1, {retain(fn) { globalThis.__retainedFunction = fn; return fn(); }});
        JS);
        $dispatch = $js->eval('globalThis.__quickjsDispatch');
        $drain = $js->eval('globalThis.__quickjsDrain');
        self::assertInstanceOf(Callback::class, $dispatch);
        $request = ['id' => 1, 'object' => -1, 'method' => 'retain', 'args' => [
            ['$quickjs' => 'function', 'id' => 7, 'source' => '() => 42'],
        ]];
        $dispatch('call', $request);
        $ready = $drain();
        self::assertTrue($ready);
        self::assertSame([['result', ['id' => 1, 'value' => 42]]], $js->drainMessages());
        self::assertSame(1, $js->eval('__quickjsTest.functionCacheSize()'));
        $request['id'] = 2;
        $request['args'][0]['source'] = '() => 99';
        $dispatch('call', $request);
        $ready = $drain();
        self::assertTrue($ready);
        self::assertSame([['result', ['id' => 2, 'value' => 42]]], $js->drainMessages(), 'Live identity must be reused');
        $dispatch('releaseFunction', ['id' => 7]);
        self::assertSame(0, $js->eval('__quickjsTest.functionCacheSize()'));
        self::assertSame(42, $js->eval('__retainedFunction()'), 'Listener-held function must remain callable');
    }
}
