<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\Tests\Support\Puppeteer\HostHttpServer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function Amp\async;
use function Amp\delay;

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

    public function testFetchStreamsUtf8BinaryAndHttpErrorBodies(): void
    {
        $server = new HostHttpServer();
        $runtime = new JsRuntime();
        try {
            self::assertSame(['Привет € 😀', true, 'TypeError', 160000, true, true, 404, false, 42, null, '', 'default'], $runtime->run(new JsFunction(<<<'JS'
                async url => {
                    const text = await fetch(url + '/split');
                    const decoded = await text.text();
                    let reused;
                    try { await text.text(); } catch (error) { reused = error.name; }
                    const binary = await fetch(url + '/binary');
                    const reader = binary.body.getReader();
                    let size = 0, maximum = 0, valid = true;
                    while (true) {
                        const {value, done} = await reader.read();
                        if (done) break;
                        for (let index = 0; index < value.length; index++) if (value[index] !== [0,255,128,65][(size + index) % 4]) valid = false;
                        size += value.length;
                        maximum = Math.max(maximum, value.length);
                    }
                    const error = await fetch(url + '/status');
                    const data = await error.json();
                    const empty = await fetch(url + '/empty');
                    return [decoded, text.bodyUsed, reused, size, maximum <= 65536, valid, error.status, error.ok, data.value,
                        empty.body, await empty.text(), new Response().type];
                }
                JS), $server->url));
            $this->assertHttpReleased($runtime);
        } finally {
            $runtime->client()->close();
            $server->close();
        }
    }

    public function testFetchRedirectModesPreserveOrRewriteRequestBodies(): void
    {
        $server = new HostHttpServer();
        $runtime = new JsRuntime();
        try {
            self::assertSame([['POST', 'Привет'], true, true, ['GET', ''], 302, false, 'TypeError', 'TypeError'], $runtime->run(new JsFunction(<<<'JS'
                async url => {
                    const first = await fetch(url + '/redirect307', {method: 'POST', body: 'Привет'});
                    const data = await first.json();
                    const second = await fetch(url + '/redirect302', {method: 'POST', body: 'discard'});
                    const rewrite = await second.json();
                    const manual = await fetch(url + '/redirect302', {redirect: 'manual'});
                    await manual.body.cancel();
                    let error, network;
                    try { await fetch(url + '/redirect302', {redirect: 'error'}); } catch (caught) { error = caught.name; }
                    try { await (await fetch(url + '/drop')).text(); } catch (caught) { network = caught.name; }
                    return [[data.method, data.body], first.redirected, first.url.endsWith('/echo'),
                        [rewrite.method, rewrite.body], manual.status, manual.redirected, error, network];
                }
                JS), $server->url));
            $this->assertHttpReleased($runtime);
        } finally {
            $runtime->client()->close();
            $server->close();
        }
    }

    public function testAbortBeforeHeadersAndDuringBodyReadReleasesHostResources(): void
    {
        $server = new HostHttpServer();
        $runtime = new JsRuntime();
        try {
            self::assertSame([true, true, 'TimeoutError', 'AbortError'], $runtime->run(new JsFunction(<<<'JS'
                async url => {
                    const reason = {message: 'stopped'};
                    let before, pending, timeout, reading;
                    try { await fetch(url, {signal: AbortSignal.abort(reason)}); } catch (error) { before = error === reason; }
                    const controller = new AbortController();
                    const promise = fetch(url + '/slow', {signal: controller.signal});
                    setTimeout(() => controller.abort(reason), 10);
                    try { await promise; } catch (error) { pending = error === reason; }
                    try { await fetch(url + '/slow', {signal: AbortSignal.timeout(5)}); } catch (error) { timeout = error.name; }
                    const bodyController = new AbortController();
                    const response = await fetch(url + '/slow-body', {signal: bodyController.signal});
                    const reader = response.body.getReader();
                    const read = reader.read();
                    setTimeout(() => bodyController.abort(), 5);
                    try { await read; } catch (error) { reading = error.name; }
                    return [before, pending, timeout, reading];
                }
                JS), $server->url));
            $this->assertHttpReleased($runtime);
        } finally {
            $runtime->client()->close();
            $server->close();
        }
    }

    public function testFetchRedirectLimitCrossOriginCredentialsAndLatin1Headers(): void
    {
        $server = new HostHttpServer();
        $target = new HostHttpServer();
        $runtime = new JsRuntime();
        try {
            self::assertSame([null, 'e9', true, 'TypeError'], $runtime->run(new JsFunction(<<<'JS'
                async (url, target) => {
                    const response = await fetch(url + '/cross?url=' + encodeURIComponent(target + '/echo'), {
                        headers: {authorization: 'Bearer secret', 'x-latin': '\u00e9'},
                    });
                    const data = await response.json();
                    let loop;
                    try { await fetch(url + '/loop'); } catch (error) { loop = error.name; }
                    return [data.authorization, data.latinHex, response.headers.get('x-latin') === '\u00e9', loop];
                }
                JS), $server->url, $target->url));
            $this->assertHttpReleased($runtime);
        } finally {
            $runtime->client()->close();
            $server->close();
            $target->close();
        }
    }

    public function testClientCloseCancelsPendingFetch(): void
    {
        $server = new HostHttpServer();
        $runtime = new JsRuntime();
        try {
            $operation = async(fn () => $runtime->run(new JsFunction('url => fetch(url + "/slow")'), $server->url));
            delay(0.01);
            $runtime->client()->close();
            try {
                $operation->await();
                self::fail('Closing the client must reject fetch');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('closed', $error->getMessage());
            }
            $this->assertHttpReleased($runtime);
        } finally {
            $server->close();
        }
    }

    private function assertHttpReleased(JsRuntime $runtime): void
    {
        $client = (new ReflectionProperty($runtime, 'client'))->getValue($runtime);
        $host = (new ReflectionProperty($client, 'http'))->getValue($client);
        if (null === $host) {
            return;
        }
        self::assertSame([], (new ReflectionProperty($host, 'requests'))->getValue($host));
    }
}
