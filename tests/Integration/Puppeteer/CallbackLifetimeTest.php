<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\RemoteObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WeakReference;

final class CallbackLifetimeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     *
     * @psalm-mutation-free
     */
    public static function operations(): iterable
    {
        foreach (['runtime', 'call', 'invoke'] as $operation) {
            yield $operation => [$operation, false];
            yield $operation . ' with failure' => [$operation, true];
        }
    }

    #[DataProvider('operations')]
    public function testConcurrentOperationsKeepCallbackUntilLastUser(string $operation, bool $fail): void
    {
        $runtime = new JsRuntime();
        $client = $runtime->client();
        try {
            $source = 'async (callback, duplicate, wait, fail) => {
                if (wait) await new Promise(resolve => { globalThis.__callbackResume = resolve; });
                if (fail) throw new Error("expected callback test failure");
                return await callback() + await duplicate();
            }';
            $function = new JsFunction($source);
            $receiver = 'runtime' === $operation ? null : $runtime->run(new JsFunction(
                'invoke' === $operation ? '() => (' . $source . ')' : '() => new class { work = (' . $source . '); }',
            ));
            if (null !== $receiver) {
                self::assertInstanceOf(RemoteObject::class, $receiver);
            }
            $callback = static fn (): int => 21;
            $weak = WeakReference::create($callback);
            $arguments = [$callback, $callback, true, false];
            $slow = $client->call($receiver?->remoteId() ?? 0, 'runtime' === $operation ? 'run' : 'work',
                'runtime' === $operation ? [$function, ...$arguments] : $arguments, $operation);
            $slow->ignore();
            $arguments[2] = false;
            $arguments[3] = $fail;
            $fast = $client->call($receiver?->remoteId() ?? 0, 'runtime' === $operation ? 'run' : 'work',
                'runtime' === $operation ? [$function, ...$arguments] : $arguments, $operation);
            unset($callback, $arguments);
            if ($fail) {
                try {
                    $fast->await();
                    self::fail('Expected the first completed operation to fail');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('expected callback test failure', $error->getMessage());
                }
            } else {
                self::assertSame(42, $fast->await());
            }
            gc_collect_cycles();
            self::assertNotNull($weak->get(), 'An unfinished operation still owns the callback');
            self::assertFalse($slow->isComplete());
            $runtime->run(new JsFunction('() => { globalThis.__callbackResume(); delete globalThis.__callbackResume; }'));
            self::assertSame(42, $slow->await());
            gc_collect_cycles();
            self::assertNull($weak->get(), 'Last completed operation must release the PHP callback');
        } finally {
            $client->close();
        }
    }

    public function testRemovingEventSubscriptionKeepsCallbackUsedByRunningOperation(): void
    {
        $runtime = new JsRuntime();
        try {
            $emitter = $runtime->run(new JsFunction('() => new class {
                handlers = new Map();
                on(event, handler) { this.handlers.set(event, handler); }
                off(event, handler) { if (this.handlers.get(event) === handler) this.handlers.delete(event); }
            }'));
            self::assertInstanceOf(RemoteObject::class, $emitter);
            $callback = static fn (): int => 42;
            $weak = WeakReference::create($callback);
            $runtime->client()->call($emitter->remoteId(), 'on', ['test', $callback])->await();
            $pending = $runtime->client()->call(0, 'run', [new JsFunction('async callback => {
                await new Promise(resolve => { globalThis.__callbackResume = resolve; });
                return await callback();
            }'), $callback], 'runtime');
            $pending->ignore();
            $runtime->client()->call($emitter->remoteId(), 'off', ['test', $callback])->await();
            unset($callback);
            gc_collect_cycles();
            self::assertNotNull($weak->get(), 'Removing subscription must preserve temporary operation ownership');
            $runtime->run(new JsFunction('() => { globalThis.__callbackResume(); delete globalThis.__callbackResume; }'));
            self::assertSame(42, $pending->await());
            gc_collect_cycles();
            self::assertNull($weak->get());
        } finally {
            $runtime->client()->close();
        }
    }

    public function testUnusedCallbackIsReleasedOnSuccessAndFailure(): void
    {
        $runtime = new JsRuntime();
        try {
            foreach ([false, true] as $fail) {
                $callback = static fn (): int => 99;
                $weak = WeakReference::create($callback);
                $future = $runtime->client()->call(0, 'run', [new JsFunction('(callback, fail) => {
                    if (fail) throw new Error("unused callback failure");
                    return 42;
                }'), $callback, $fail], 'runtime');
                unset($callback);
                if ($fail) {
                    try {
                        $future->await();
                        self::fail('Expected an operation failure');
                    } catch (RuntimeException $error) {
                        self::assertStringContainsString('unused callback failure', $error->getMessage());
                    }
                } else {
                    self::assertSame(42, $future->await());
                }
                gc_collect_cycles();
                self::assertNull($weak->get(), 'Callback invocation is not required for release');
            }
        } finally {
            $runtime->client()->close();
        }
    }
}
