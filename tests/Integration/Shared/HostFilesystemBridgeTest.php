<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use Nesk\Puphpeteer\Client;
use Nesk\Puphpeteer\Tests\Support\Shared\AsyncGuest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostFilesystemBridgeTest extends TestCase
{
    public function testFilesystemMessagesTransferBinaryDataAndPropagateErrors(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'fs-bridge-');
        $output = tempnam(sys_get_temp_dir(), 'fs-output-');
        self::assertIsString($bundle);
        self::assertIsString($output);
        file_put_contents($bundle, AsyncGuest::wrap(<<<'JS'
        globalThis.__quickjsDispatch = (kind, payload) => {
          if (kind === 'call') {
            const args = payload.args;
            if (payload.method === 'write') args.push({$quickjs:'bytes', value:new Uint8Array([0,255,195,169])});
            __testEmit('filesystem', {id:payload.id, operation:payload.method, args});
          } else if (kind === 'callbackResult') {
            if (!payload.error && payload.value instanceof Uint8Array) {
              if (payload.value[0] !== 255) throw new Error('Missing host binary marker');
              payload.value = {$quickjs:'bytes', value:payload.value.subarray(1)};
            }
            __testEmit('result', payload);
          }
        };
        JS));
        try {
            $client = new Client($bundle);
            try {
                $client->call(1, 'write', [$output])->await();
                self::assertSame("\0\xffé", file_get_contents($output));
                self::assertSame("\0\xffé", $client->call(1, 'read', [$output])->await());
                foreach (['', 'ASCII', 'é', implode('', array_map(chr(...), range(0, 255)))] as $content) {
                    file_put_contents($output, $content);
                    self::assertSame($content, $client->call(1, 'read', [$output])->await());
                }
                file_put_contents($output, "\0\xffé");
                try {
                    $client->call(1, 'read', [$output . '/missing'])->await();
                    self::fail('Expected error');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('Filesystem read failed:', $error->getMessage());
                }
                self::assertSame("\0\xffé", $client->call(1, 'read', [$output])->await(), 'I/O error must not close the client');
            } finally {
                $client->close();
            }
        } finally {
            unlink($bundle);
            unlink($output);
        }
    }
}
