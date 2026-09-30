<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Shared;

use Nesk\Puphpeteer\Internal\HostFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HostFilesystemTest extends TestCase
{
    public function testBinaryWritesAppendAndTruncateAndCleanup(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'puphpeteer-fs-');
        self::assertIsString($path);
        $fs = new HostFilesystem();
        try {
            $fs->call('write', [$path, "\x00\xffé"]);
            self::assertSame("\x00\xffé", file_get_contents($path));
            self::assertSame("\xff\x00\xffé", $fs->call('read', [$path]));
            $id = $fs->call('open', [$path]);
            $fs->call('append', [$id, 'first']);
            $fs->call('append', [$id, 'second']);
            $fs->closeAll();
            self::assertSame('firstsecond', file_get_contents($path));
            $this->expectException(RuntimeException::class);
            $fs->call('append', [$id, 'closed']);
        } finally {
            $fs->closeAll();
            unlink($path);
        }
    }

    public function testNativeBinaryReadsIncludeMarkerForEveryContent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'puphpeteer-fs-');
        self::assertIsString($path);
        try {
            $fs = new HostFilesystem();
            foreach (['', 'ASCII', 'é', "\xff", implode('', array_map(chr(...), range(0, 255)))] as $content) {
                file_put_contents($path, $content);
                self::assertSame("\xff" . $content, $fs->call('read', [$path]));
            }
        } finally {
            unlink($path);
        }
    }

    public function testIoFailureRestoresErrorHandler(): void
    {
        $handler = static fn (): bool => true;
        set_error_handler($handler);
        try {
            try {
                (new HostFilesystem())->call('read', [__DIR__ . '/missing/file']);
                self::fail('Expected read failure');
            } catch (RuntimeException $error) {
                self::assertStringStartsWith('Filesystem read failed:', $error->getMessage());
            }
            $previous = set_error_handler($handler);
            self::assertSame($handler, $previous);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }
}
