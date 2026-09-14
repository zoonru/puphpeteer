<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Shared;

use Nesk\Puphpeteer\Client;
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
        file_put_contents($bundle, <<<'JS'
        globalThis.__quickjsDispatch = (kind, payload) => {
          if (kind === 'call') {
            const args = payload.args;
            if (payload.method === 'write') args.push({$quickjs:'bytes', value:new Uint8Array([0,255,195,169])});
            __quickjsEmit('filesystem', {id:payload.id, operation:payload.method, args});
          } else if (kind === 'callbackResult') {
            __quickjsEmit('result', payload);
          }
        };
        JS);
        try {
            $client = new Client($bundle);
            try {
                $client->call(1, 'write', [$output])->await();
                self::assertSame("\0\xffé", file_get_contents($output));
                self::assertSame(base64_encode("\0\xffé"), $client->call(1, 'read', [$output])->await());
                try {
                    $client->call(1, 'read', [$output . '/missing'])->await();
                    self::fail('Expected error');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('file_get_contents', $error->getMessage());
                }
                self::assertSame(base64_encode("\0\xffé"), $client->call(1, 'read', [$output])->await(), 'I/O error must not close the client');
            } finally {
                $client->close();
            }
        } finally {
            unlink($bundle);
            unlink($output);
        }
    }
}
