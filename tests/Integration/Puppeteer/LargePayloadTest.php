<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Tests\Support\Puppeteer\LargePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class LargePayloadTest extends TestCase
{
    /** @psalm-mutation-free */
    public static function sizes(): iterable
    {
        foreach ([2097151, 2097153, 4194321, 8388639, 12582943] as $size) {
            yield (string) $size => [$size];
        }
    }

    #[DataProvider('sizes')]
    public function testLargeFunctionResultsBinaryAndStreamsAreExact(int $size): void
    {
        $client = LargePayload::client();
        try {
            $client->call(-1, 'prepare', [$size])->await();
            $expected = LargePayload::expected($size) . LargePayload::SUFFIX;
            $fn = new JsFunction('(text, suffix) => ({payload: text + suffix, marker: "end"})');
            $result = $client->call(-1, 'invoke', [$fn, LargePayload::SUFFIX])->await();
            self::assertSame('end', $result['marker']);
            self::assertSame(strlen($expected), strlen($result['payload']));
            self::assertSame(hash('sha256', $expected), hash('sha256', $result['payload']));
            // Exercise the opposite direction and UTF-8/NUL/JSON escapes too.
            self::assertSame(hash('sha256', $expected), hash('sha256', $client->call(-1, 'invoke', [new JsFunction('(_, value) => value'), $expected])->await()));
            unset($result, $expected);
            $expected = LargePayload::expected($size, true);
            $value = $client->call(-1, 'bytes', [])->await();
            self::assertSame($size, strlen($value));
            self::assertSame(hash('sha256', $expected), hash('sha256', $value));
            unset($value);
            // One oversized backing buffer and uneven producer chunks must both survive.
            foreach ([$size, 65537] as $chunkSize) {
                $digest = LargePayload::digest($client->call(-1, 'stream', [$chunkSize])->await());
                self::assertSame($size, $digest['bytes']);
                self::assertSame(hash('sha256', $expected), $digest['sha256']);
                self::assertLessThanOrEqual(65536, $digest['max_chunk']);
            }
            self::assertSame([], (new ReflectionProperty($client, 'streams'))->getValue($client));
            self::assertSame([], (new ReflectionProperty($client, 'pending'))->getValue($client));
        } finally {
            $client->close();
        }
    }

    public function testStreamsExceedSingleValueLimitAndRejectedResultsDoNotCorruptClient(): void
    {
        $client = LargePayload::client();
        try {
            $size = 32 * 1024 * 1024 + 17;
            $client->call(-1, 'prepare', [$size])->await();
            try {
                $client->call(-1, 'bytes', [])->await();
                self::fail('Oversized single value must be rejected explicitly');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('bridge value exceeds size limit', $error->getMessage());
            }
            $digest = LargePayload::digest($client->call(-1, 'stream', [$size])->await());
            self::assertSame($size, $digest['bytes']);
            self::assertSame(hash('sha256', LargePayload::expected($size, true)), $digest['sha256']);
            self::assertLessThanOrEqual(65536, $digest['max_chunk']);
            self::assertSame(42, $client->call(-1, 'invoke', [new JsFunction('() => 42')])->await());
        } finally {
            $client->close();
        }
    }

    public function testConcurrentLargeStreamsDoNotMixChunks(): void
    {
        $client = LargePayload::client();
        try {
            $size = 4 * 1024 * 1024 + 17;
            $client->call(-1, 'prepare', [$size])->await();
            $streams = [
                $client->call(-1, 'stream', [65537])->await(),
                $client->call(-1, 'stream', [131071])->await(),
            ];
            $expectedHash = hash('sha256', LargePayload::expected($size, true));
            foreach (\Amp\Future\await(array_map(static fn ($stream) => \Amp\async(static fn () => LargePayload::digest($stream)), $streams)) as $digest) {
                self::assertSame($size, $digest['bytes']);
                self::assertSame($expectedHash, $digest['sha256']);
            }
            self::assertSame([], (new ReflectionProperty($client, 'streams'))->getValue($client));
        } finally {
            $client->close();
        }
    }
}
