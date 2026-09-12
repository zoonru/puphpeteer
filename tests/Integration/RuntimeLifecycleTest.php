<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Tests\Integration;

use Amp\DeferredCancellation;
use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\RemoteObject;
use PHPUnit\Framework\TestCase;

final class RuntimeLifecycleTest extends TestCase
{
    private function client(): Client
    {
        $file = tempnam(sys_get_temp_dir(), 'quickjs-runtime-');
        if ($file === false) { throw new \RuntimeException('Cannot create fixture'); }
        file_put_contents($file, <<<'JS'
        globalThis.__quickjsDispatch = (kind, payload) => {
          if (kind !== 'call' || payload.method === 'pending') return;
          __quickjsEmit('result', {id: payload.id, value: payload.args[0] ?? 42});
        };
        JS);
        try { return new Client($file); }
        finally { unlink($file); }
    }

    public function testCancellationRejectsAllPendingOperationsAndClosesClient(): void
    {
        $client = $this->client();
        $cancellation = new DeferredCancellation();
        $first = $client->call(1, 'pending', [], cancellation: $cancellation->getCancellation());
        $second = $client->call(1, 'pending', []);
        $cancellation->cancel();
        foreach ([$first, $second] as $future) {
            try { $future->await(); self::fail('Cancellation must reject pending calls'); }
            catch (\Amp\CancelledException) { self::assertTrue(true); }
        }
        $this->expectExceptionMessage('QuickJS client is closed');
        $client->call(1, 'next', [])->await();
    }

    public function testReleaseIsIdempotentAndRejectsUseLocally(): void
    {
        $client = $this->client();
        $object = new RemoteObject($client, 1, 'Object');
        self::assertSame(42, $object->__call('value', []));
        $object->release();
        $object->release();
        self::assertFalse($object->belongsTo($client));
        $client->close();
        $this->expectException(\RuntimeException::class);
        $object->__call('value', []);
    }

    public function testForeignObjectIsRejected(): void
    {
        $client = $this->client();
        $other = $this->client();
        $object = new RemoteObject($other, 1, 'Object');
        try {
            $this->expectExceptionMessage('Remote object belongs to another client');
            $client->call(1, 'echo', [$object])->await();
        } finally { $client->close(); $other->close(); }
    }

    public function testProtocolLookingUserDataIsRoundTrippedWithoutInterpretation(): void
    {
        $client = $this->client();
        $data = ['$quickjs' => 'object', 'id' => 123, 'nested' => ['$quickjs' => 'undefined']];
        try { self::assertSame($data, $client->call(1, 'echo', [$data])->await()); }
        finally { $client->close(); }
    }

    public function testCyclicInputFailsWithoutClosingTransport(): void
    {
        $client = $this->client();
        $data = [];
        $data['cycle'] = &$data;
        try {
            try { $client->call(1, 'echo', [$data])->await(); self::fail('Cycle accepted'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('depth 64', $error->getMessage()); }
            self::assertSame(42, $client->call(1, 'echo', [42])->await());
        } finally { $client->close(); }
    }

    public function testCloseRejectsPendingAndIsIdempotent(): void
    {
        $client = $this->client();
        $pending = $client->call(1, 'pending', []);
        $client->close();
        $client->close();
        $this->expectExceptionMessage('QuickJS client closed');
        $pending->await();
    }
}
