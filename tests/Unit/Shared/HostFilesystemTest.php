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
            self::assertSame(base64_encode("\x00\xffé"), $fs->call('read', [$path]));
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

    public function testRecordingCreatesDirectoriesAndProtectsExistingFiles(): void
    {
        $directory = sys_get_temp_dir() . '/puphpeteer-recording-fs-' . bin2hex(random_bytes(8));
        $path = $directory . '/video.mp4';
        $fs = new HostFilesystem();
        try {
            $id = $fs->call('openRecording', [$path, false]);
            $fs->call('append', [$id, 'original']);
            $fs->call('close', [$id]);
            try {
                $fs->call('openRecording', [$path, false]);
                self::fail('Expected exclusive creation failure');
            } catch (RuntimeException) {
                self::assertSame('original', file_get_contents($path));
            }
            $id = $fs->call('openRecording', [$path, true]);
            $fs->call('append', [$id, 'replacement']);
            $fs->call('close', [$id]);
            self::assertSame('replacement', file_get_contents($path));
        } finally {
            $fs->closeAll();
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
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
                self::assertStringContainsString('file_get_contents', $error->getMessage());
            }
            $previous = set_error_handler($handler);
            self::assertSame($handler, $previous);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }
}
