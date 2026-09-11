<?php

declare(strict_types=1);

use Nesk\Puphpeteer\Internal\ChannelEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelEndpointTest extends TestCase
{
    public function testReadsChromePortFileWithWindowsLineEndings(): void
    {
        self::assertSame('ws://localhost:9222/devtools/browser/abc-123', ChannelEndpoint::parse("9222\r\n/devtools/browser/abc-123\r\n"));
    }

    #[DataProvider('invalidFiles')]
    public function testRejectsIncompleteOrInvalidEndpointFile(string $contents): void
    {
        $this->expectException(UnexpectedValueException::class);
        ChannelEndpoint::parse($contents);
    }

    public static function invalidFiles(): array
    {
        return [["9222\n"], ["0\n/devtools/browser/id"], ["65536\n/devtools/browser/id"], ["9222junk\n/devtools/browser/id"], ["9222\n//example.com/path"], ["9222\n/devtools/browser/id#fragment"]];
    }
}
