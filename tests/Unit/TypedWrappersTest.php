<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit;

use Amp\Future;
use Nesk\Puphpeteer\Browser;
use Nesk\Puphpeteer\BrowserContext;
use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Page;
use Nesk\Puphpeteer\RemoteObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TypedWrappersTest extends TestCase
{
    private function client(): Client
    {
        return (new ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    }

    private function decode(Client $client, mixed $value): mixed
    {
        return (new ReflectionMethod(Client::class, 'decode'))->invoke($client, $value);
    }

    public function testConstructorAliasesProduceTypedObjectsAndPreserveIdentity(): void
    {
        $client = $this->client();
        $references = [
            ['$quickjs' => 'object', 'id' => 1, 'class' => 'Browser'],
            ['$quickjs' => 'object', 'id' => 2, 'class' => 'BrowserContext'],
            ['$quickjs' => 'object', 'id' => 3, 'class' => 'Page'],
        ];
        $objects = $this->decode($client, $references);
        self::assertInstanceOf(Browser::class, $objects[0]);
        self::assertInstanceOf(BrowserContext::class, $objects[1]);
        self::assertInstanceOf(Page::class, $objects[2]);
        self::assertSame($objects, $this->decode($client, $references));
        self::assertSame(Page::class, $objects[2]::class);
        self::assertSame(3, $objects[2]->remoteId());
        self::assertSame(
            ['$quickjs' => 'object', 'id' => 3],
            (new ReflectionMethod(Client::class, 'encode'))->invoke($client, $objects[2]),
        );
    }

    public function testUnknownConstructorCannotSelectArbitraryPhpClass(): void
    {
        $client = $this->client();
        foreach (['UnknownPluginClass', Client::class, '\\stdClass'] as $index => $class) {
            $object = $this->decode($client, ['$quickjs' => 'object', 'id' => $index + 1, 'class' => $class]);
            self::assertSame(RemoteObject::class, $object::class);
            self::assertSame($index + 1, $object->remoteId());
        }
    }

    public function testTypedMethodsDispatchWithoutOptionalPadding(): void
    {
        $page = new class($this->client(), 7, 'Page') extends Page {
            public array $calls = [];

            /** @psalm-external-mutation-free */
            #[\Override]
            protected function invokeRemote(string $method, array $arguments): mixed
            {
                $this->calls[] = [$method, $arguments];
                return match ($method) { 'goto' => null, 'title' => 'title', default => 42 };
            }
        };
        $function = new JsFunction('(a, b) => a + b');
        self::assertSame(42, $page->evaluate($function, 20, 22));
        $page->goto('https://example.test');
        $page->title();
        self::assertSame([
            ['evaluate', [$function, 20, 22]],
            ['goto', ['https://example.test']],
            ['title', []],
        ], $page->calls);
    }

    public function testGeneratedPropertyUsesTheRemoteGetter(): void
    {
        $browser = new class($this->client(), 1, 'Browser') extends Browser {
            /** @psalm-pure */
            #[\Override]
            protected function getRemote(string $name): mixed { return $name === 'connected'; }
        };
        self::assertTrue($browser->connected);
    }

    public function testTypedObjectRetainsDynamicFallbackAndClosedClientError(): void
    {
        $client = $this->client();
        $page = new Page($client, 7, 'Page');
        $client->close();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('QuickJS client is closed');
        $page->__call('pluginSpecificMethod', []);
    }
}
