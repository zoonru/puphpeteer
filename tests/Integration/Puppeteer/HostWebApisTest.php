<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use PHPUnit\Framework\TestCase;

final class HostWebApisTest extends TestCase
{
    public function testStructuredClonePreservesGraphsAndNativeTypes(): void
    {
        $runtime = new JsRuntime();
        self::assertSame([true, true, true, true, true, true, true, 'DataCloneError'], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const input = {headers: {accept: 'text/html'}, data: new Uint8Array([1, 2]), date: new Date(42)};
                input.self = input;
                input.map = new Map([['data', input.data]]);
                input.set = new Set([input.data]);
                const copy = structuredClone(input);
                copy.headers.accept = 'changed';
                let error;
                try { structuredClone(() => {}); } catch (caught) { error = caught.name; }
                return [copy !== input, copy.self === copy, input.headers.accept === 'text/html',
                    copy.map instanceof Map && copy.map.get('data') === copy.data,
                    copy.set instanceof Set && copy.set.has(copy.data),
                    copy.data instanceof Uint8Array && copy.data[1] === 2,
                    copy.date instanceof Date && copy.date.getTime() === 42, error];
            }
            JS)));
    }

    public function testStructuredCloneTransfersArrayBuffers(): void
    {
        $runtime = new JsRuntime();
        self::assertSame([0, 3, 42], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const buffer = new Uint8Array([42, 1, 2]).buffer;
                const copy = structuredClone(buffer, {transfer: [buffer]});
                return [buffer.byteLength, copy.byteLength, new Uint8Array(copy)[0]];
            }
            JS)));
    }

    public function testImmediateCallbacksSupportArgumentsCancellationAndMicrotaskOrder(): void
    {
        $runtime = new JsRuntime();
        self::assertSame(['microtask', 'immediate'], $runtime->run(new JsFunction(<<<'JS'
            () => new Promise(resolve => {
                const order = [];
                clearImmediate(setImmediate(() => order.push('cancelled')));
                setImmediate(value => { order.push(value); resolve(order); }, 'immediate');
                queueMicrotask(() => order.push('microtask'));
            })
            JS)));
    }

}
