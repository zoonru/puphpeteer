<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit;

use Amp\DeferredFuture;
use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\RemoteObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class ClientTest extends TestCase
{
    private function client(): Client
    {
        // Isolate PHP lifecycle and codec behavior from the native engine.
        return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    private function codec(Client $client, string $method, mixed $value): mixed
    {
        return (new ReflectionMethod(Client::class, $method))->invoke($client, $value);
    }

    public function testCloseFailsOutstandingOperationsAndIsIdempotent(): void
    {
        $client = $this->client();
        $pending = new DeferredFuture();
        (new ReflectionProperty(Client::class, 'pending'))->setValue($client, [1 => $pending]);
        $client->close();
        $client->close();

        self::assertTrue($pending->getFuture()->isComplete());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('QuickJS client closed');
        $pending->getFuture()->await();
    }

    public function testRemoteCallAfterCloseReturnsFailedFuture(): void
    {
        $client = $this->client();
        $page = new RemoteObject($client, 7, 'Page');
        $client->close();
        $page->release();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('QuickJS client is closed');
        $page->__call('title', []);
    }

    public function testNestedDataRoundTripsWithoutChangingBinaryOrScalarValues(): void
    {
        $client = $this->client();
        $data = ['text' => 'Привет 👋', 'binary' => "\0\xff\xfe", 'values' => [null, false, 42, 3.5, []]];
        self::assertSame($data, $this->codec($client, 'decode', $this->codec($client, 'encode', $data)));
    }

    public function testFunctionSourceAndObjectReferencesAreEncodedInsideArguments(): void
    {
        $client = $this->client();
        $page = new RemoteObject($client, 7, 'Page');
        self::assertSame(
            [['$quickjs' => 'function', 'id' => 1, 'source' => '(value) => value'], ['$quickjs' => 'object', 'id' => 7]],
            $this->codec($client, 'encode', [new JsFunction('(value) => value'), $page]),
        );
    }

    public function testCallbackAndFunctionIdentityIsStable(): void
    {
        $client = $this->client();
        $callback = static fn(): int => 42;
        $function = new JsFunction('() => 42');
        foreach ([$callback, $function] as $value) {
            self::assertSame($this->codec($client, 'encode', $value), $this->codec($client, 'encode', $value));
        }
        self::assertNotSame($this->codec($client, 'encode', $function), $this->codec($client, 'encode', new JsFunction('() => 42')));
    }

    public function testDecodedReferencesKeepIdentityUntilRelease(): void
    {
        $client = $this->client();
        $reference = ['$quickjs' => 'object', 'id' => 9, 'class' => 'Page'];
        $first = $this->codec($client, 'decode', $reference);
        self::assertInstanceOf(RemoteObject::class, $first);
        self::assertSame($first, $this->codec($client, 'decode', $reference));
        self::assertSame(\Nesk\Puphpeteer\Page::class, $first::class);
        self::assertSame(9, $first->remoteId());

        $client->close();
        $first->release();
        self::assertNotSame($first, $this->codec($client, 'decode', $reference));
    }

    public function testBinaryResultsAreReturnedAsExactPhpStrings(): void
    {
        $client = $this->client();
        $bytes = "\0\xff\xfe" . str_repeat('x', 65_536);
        self::assertSame($bytes, $this->codec($client, 'decode', ['$quickjs' => 'bytes', 'value' => $bytes]));
    }

    public function testUndefinedBecomesNull(): void
    {
        $client = $this->client();
        $special = ['$quickjs' => 'undefined'];
        self::assertSame(null, $this->codec($client, 'decode', $special));
    }

    public function testMissingOptimizedExtensionHasActionableError(): void
    {
        if (method_exists(\Js\Callback::class, 'dispatch')) {
            self::assertTrue(class_exists(\QuickJS::class));
            return;
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dispatch');
        new Client();
    }
}
