<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Integration\Puppeteer;

use Nesk\Puphpeteer\Client;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class LoggingTest extends TestCase
{
    public function testBridgeForwardsLogLevelsToPsrLogger(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'quickjs-logging-');
        if (false === $bundle) {
            throw new RuntimeException('Cannot create fixture');
        }
        file_put_contents($bundle, <<<'JS'
            globalThis.__quickjsDispatch = (kind, payload) => {
              if (kind !== 'call') return;
              __quickjsEmit('log', {level: 'debug', message: 'details'});
              __quickjsEmit('log', {level: 'warning', message: 'careful'});
              __quickjsEmit('result', {id: payload.id, value: 42});
            };
            JS);
        $logger = new class extends AbstractLogger {
            public array $records = [];

            /** @psalm-external-mutation-free */
            #[Override]
            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        try {
            $client = new Client($bundle, $logger);
            self::assertSame(42, $client->call(0, 'log', [])->await());
            self::assertSame([
                ['debug', 'details', []],
                ['warning', 'careful', []],
            ], $logger->records);
            $client->close();
        } finally {
            unlink($bundle);
        }
    }
}
