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

    public function testTextEncodingSupportsBufferViewsAndEncodeInto(): void
    {
        $runtime = new JsRuntime();
        self::assertSame(['€', [0, 0], [2, 4], [240, 159, 152, 128], '�', 'TypeError', true], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const decoder = new TextDecoder();
                const view = new DataView(new Uint8Array([0, 0xe2, 0x82, 0xac, 0]).buffer, 1, 3);
                const encoder = new TextEncoder();
                const small = encoder.encodeInto('😀A', new Uint8Array(3));
                const destination = new Uint8Array(4);
                const exact = encoder.encodeInto('😀A', destination);
                let error;
                try { new TextDecoder('utf-8', {fatal: true}).decode(new Uint8Array([0xc3, 0x28])); }
                catch (caught) { error = caught.name; }
                const large = 'Привет 😀'.repeat(10000);
                return [new TextDecoder().decode(view), [small.read, small.written],
                    [exact.read, exact.written], Array.from(destination),
                    decoder.decode(encoder.encode('\ud800')), error,
                    decoder.decode(encoder.encode(large)) === large];
            }
            JS)));
    }

    public function testEncoderHandlesMaximumExpansionAndPartialDestinations(): void
    {
        $runtime = new JsRuntime();
        self::assertTrue($runtime->run(new JsFunction(<<<'JS'
            () => {
                const encoder = new TextEncoder();
                const text = '€'.repeat(65536);
                const encoded = encoder.encode(text);
                if (encoded.length !== text.length * 3 || encoded.buffer.byteLength !== text.length * 3) return false;
                if (!encoded.every((byte, index) => byte === [0xe2, 0x82, 0xac][index % 3])) return false;
                for (const surrogate of ['\ud800', '\udfff']) {
                    if (Array.from(encoder.encode(surrogate)).join() !== '239,191,189') return false;
                }
                const source = 'A😀€';
                for (let capacity = 0; capacity <= 9; capacity++) {
                    const destination = new Uint8Array(capacity).fill(0x55);
                    const result = encoder.encodeInto(source, destination);
                    const read = capacity < 1 ? 0 : capacity < 5 ? 1 : capacity < 8 ? 3 : 4;
                    const written = capacity < 1 ? 0 : capacity < 5 ? 1 : capacity < 8 ? 5 : 8;
                    if (result.read !== read || result.written !== written) return false;
                    if (new TextDecoder().decode(destination.subarray(0, written)) !== source.slice(0, read)) return false;
                    if (!destination.subarray(written).every(byte => byte === 0x55)) return false;
                }
                return true;
            }
            JS)));
    }

    public function testStreamingDecodePreservesScalarsBomAndBufferViews(): void
    {
        $runtime = new JsRuntime();
        self::assertTrue($runtime->run(new JsFunction(<<<'JS'
            () => {
                const text = '\ufeffПривет € 😀\ufeff';
                const bytes = new TextEncoder().encode(text);
                for (const ignoreBOM of [false, true]) {
                    for (const fatal of [false, true]) {
                        for (let width = 1; width <= bytes.length; width++) {
                            const decoder = new TextDecoder('utf-8', {ignoreBOM, fatal});
                            let output = '';
                            for (let index = 0; index < bytes.length; index += width) {
                                output += decoder.decode(new DataView(bytes.buffer, index, Math.min(width, bytes.length - index)), {stream: true});
                                output += decoder.decode(undefined, {stream: true});
                            }
                            output += decoder.decode();
                            if (output !== (ignoreBOM ? text : text.slice(1))) return false;
                            if (decoder.decode(bytes) !== (ignoreBOM ? text : text.slice(1))) return false;
                        }
                    }
                }
                const large = 'Я😀€'.repeat(20000);
                const largeBytes = new TextEncoder().encode(large);
                const largeDecoder = new TextDecoder();
                let largeOutput = '';
                for (let offset = 0; offset < largeBytes.length; offset += 32767) {
                    largeOutput += largeDecoder.decode(largeBytes.subarray(offset, offset + 32767), {stream: true});
                }
                if (largeOutput + largeDecoder.decode() !== large) return false;
                // The decoder must copy a partial scalar out of the input buffer.
                const decoder = new TextDecoder();
                const prefix = new Uint8Array([0xf0, 0x9f, 0x98]);
                if (decoder.decode(prefix, {stream: true}) !== '') return false;
                prefix.fill(0);
                return decoder.decode(new Uint8Array([0x80])) === '😀';
            }
            JS)));
    }

    public function testStreamingDecodeFlushesAndRecoversFromInvalidUtf8(): void
    {
        $runtime = new JsRuntime();
        self::assertSame(['', '�', '', '', 'TypeError', 'A', '', '�(', '€', '�(', '�A'], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const decoder = new TextDecoder();
                const prefix = new Uint8Array([0xe2, 0x82]);
                const partial = decoder.decode(prefix, {stream: true});
                const flush = decoder.decode();
                const empty = decoder.decode();
                const fatal = new TextDecoder('utf-8', {fatal: true});
                const fatalPartial = fatal.decode(prefix, {stream: true});
                let error;
                try { fatal.decode(); } catch (caught) { error = caught.name; }
                const reused = fatal.decode(new Uint8Array([65]));
                const pending = decoder.decode(prefix, {stream: true});
                const invalid = decoder.decode(new Uint8Array([40]), {stream: true});
                const complete = decoder.decode(new Uint8Array([0xe2, 0x82, 0xac]));
                return [partial, flush, empty, fatalPartial, error, reused, pending, invalid, complete,
                    decoder.decode(new Uint8Array([0xc3, 40])), decoder.decode(new Uint8Array([0xe2, 0x82, 65]))];
            }
            JS)));
    }

    public function testUnicodeOutputChunksPreserveSurrogatePairsAndBom(): void
    {
        $runtime = new JsRuntime();
        self::assertTrue($runtime->run(new JsFunction(<<<'JS'
            () => {
                const encoder = new TextEncoder();
                const decoder = new TextDecoder();
                const boundaries = String.fromCodePoint(0, 0x7f, 0x80, 0x7ff, 0x800, 0xd7ff, 0xe000, 0xffff, 0x10000, 0x10ffff);
                for (const length of [1, 32765, 32766, 32767, 32768, 65535]) {
                    const text = 'а'.repeat(length) + '😀' + boundaries + 'Я'.repeat(length) + '🚀';
                    if (decoder.decode(encoder.encode(text)) !== text) return false;
                }
                const text = '\ufeff' + 'Привет 😀'.repeat(10000) + '\ufeff';
                const bytes = encoder.encode(text);
                return decoder.decode(bytes) === text.slice(1)
                    && new TextDecoder('utf-8', {ignoreBOM: true}).decode(bytes) === text;
            }
            JS)));
    }

    public function testTextEncodingUsesWebApiCoercionsAndDestinationType(): void
    {
        $runtime = new JsRuntime();
        self::assertSame(['42', 'null', 'utf-8', 'TypeError', 'a', "\u{feff}a"], $runtime->run(new JsFunction(<<<'JS'
            () => {
                const encoder = new TextEncoder('ignored-label');
                const decoder = new TextDecoder('utf-8', null);
                const destination = new Uint8Array(4);
                encoder.encodeInto(null, destination);
                let error;
                try { encoder.encodeInto('a', new Uint16Array(1)); }
                catch (caught) { error = caught.name; }
                const bom = new Uint8Array([0xef, 0xbb, 0xbf, 97]);
                return [decoder.decode(encoder.encode(42)), decoder.decode(destination),
                    encoder.encoding, error, decoder.decode(bom),
                    new TextDecoder('utf-8', {ignoreBOM: true}).decode(bom)];
            }
            JS)));
    }
}
