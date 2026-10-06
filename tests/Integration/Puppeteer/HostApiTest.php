<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use PHPUnit\Framework\TestCase;

final class HostApiTest extends TestCase
{
    public function testCryptoUsesHostEntropyAndDigestVectors(): void
    {
        $runtime = new JsRuntime();
        self::assertSame([true, true, 'TypeMismatchError', 'QuotaExceededError', true,
            hash('sha256', 'abc'), hash('sha1', 'abc'), hash('sha384', 'abc'), hash('sha512', 'abc'), 'NotSupportedError'], $runtime->run(new JsFunction(<<<'JS'
            async () => {
                const buffer = new Uint8Array(40).fill(123);
                const view = new Uint32Array(buffer.buffer, 4, 8);
                const same = crypto.getRandomValues(view) === view &&
                    [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array, Int32Array, Uint32Array, BigInt64Array, BigUint64Array]
                    .every(Type => { const value = new Type(1); return crypto.getRandomValues(value) === value; });
                let type, quota;
                try { crypto.getRandomValues(new Float64Array(1)); } catch (error) { type = error.name; }
                try { crypto.getRandomValues(new Uint8Array(65537)); } catch (error) { quota = error.name; }
                crypto.getRandomValues(new Uint8Array(0));
                const uuid = crypto.randomUUID();
                const bytes = new TextEncoder().encode('_abc_');
                const data = new DataView(bytes.buffer, 1, 3);
                const digests = [];
                for (const name of ['SHA-256', 'SHA-1', 'SHA-384', 'SHA-512']) {
                    const hash = await crypto.subtle.digest({name}, data);
                    digests.push(Array.from(new Uint8Array(hash), value => value.toString(16).padStart(2, '0')).join(''));
                }
                let unsupported;
                try { await crypto.subtle.encrypt({}, new Uint8Array()); } catch (error) { unsupported = error.name; }
                return [same, buffer.slice(0, 4).every(value => value === 123) && buffer.slice(36).every(value => value === 123),
                    type, quota, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(uuid), ...digests, unsupported];
            }
            JS)));
    }
}
