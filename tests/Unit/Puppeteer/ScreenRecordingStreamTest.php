<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Unit\Puppeteer;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\File\File;
use Amp\File\FilesystemDriver;
use Nesk\Puphpeteer\Internal\ScreenRecordingStream;
use Nesk\Puphpeteer\Puppeteer\ScreenRecording;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function Amp\async;
use function Amp\delay;
use function Amp\File\filesystem;

final class ScreenRecordingStreamTest extends TestCase
{
    private FilesystemDriver $originalDriver;

    #[Override]
    protected function setUp(): void
    {
        $driver = (new ReflectionProperty(filesystem(), 'driver'))->getValue(filesystem());
        self::assertInstanceOf(FilesystemDriver::class, $driver);
        $this->originalDriver = $driver;
    }

    #[Override]
    protected function tearDown(): void
    {
        filesystem($this->originalDriver);
    }

    private function file(File $file): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('getStatus')->willReturn(['mode' => 0040000]);
        $driver->method('openFile')->willReturn($file);
        filesystem($driver);
    }

    private function recording(ReadableStream $stream): ScreenRecording
    {
        $recording = $this->getMockBuilder(ScreenRecording::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRemoteStream', 'stopRemote', '__destruct'])
            ->getMock();
        $recording->method('getRemoteStream')->willReturn($stream);

        return $recording;
    }

    public function testWithoutPathKeepsTheLiveStream(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        filesystem($driver);
        $recording = $this->recording(new ReadableBuffer('video'));
        $live = ScreenRecordingStream::start(['fps' => 20], static fn () => $recording);
        self::assertSame('video', $live->read());
        self::assertNull($live->read());
    }

    public function testFileIsPipedWithoutPublicReaderAndOptionsStayInPhp(): void
    {
        $file = $this->createMock(File::class);
        $file->expects(self::once())->method('write')->with("\0\xffvideo");
        $file->expects(self::once())->method('close');
        $this->file($file);
        $recording = $this->recording(new ReadableBuffer("\0\xffvideo"));
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4', 'overwrite' => false, 'fps' => 20], static function (array $options) use ($recording): ScreenRecording {
            self::assertSame(['fps' => 20], $options);

            return $recording;
        });
        delay(0);
        self::assertTrue($saved->isClosed());
        self::assertNull($saved->read());
        $saved->stop();
        $saved->stop();
        $saved->close();
        $saved->close();
    }

    public function testLargeFileIsDrainedWithoutAReader(): void
    {
        $written = 0;
        $file = $this->createMock(File::class);
        $file->expects(self::exactly(256))->method('write')->willReturnCallback(static function (string $bytes) use (&$written): void {
            self::assertSame(65536, strlen($bytes));
            $written += strlen($bytes);
        });
        $file->expects(self::once())->method('close');
        $this->file($file);
        $source = new ReadableIterableStream((static function () {
            for ($chunk = 0; $chunk < 256; ++$chunk) {
                yield str_repeat('v', 65536);
            }
        })());
        $recording = $this->recording($source);
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        $saved->stop();
        self::assertSame(16 * 1024 * 1024, $written);
        self::assertNull($saved->read());
    }

    public function testCancellationStopsRecordingAndClosesFile(): void
    {
        $finished = new DeferredFuture();
        $source = new ReadableIterableStream((static function () use ($finished) {
            $finished->getFuture()->await();
            yield 'video';
        })());
        $file = $this->createMock(File::class);
        $file->expects(self::once())->method('close');
        $this->file($file);
        $recording = $this->getMockBuilder(ScreenRecording::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRemoteStream', 'stopRemote', '__destruct'])
            ->getMock();
        $recording->method('getRemoteStream')->willReturn($source);
        $recording->expects(self::once())->method('stopRemote')->willReturnCallback(static fn () => $finished->complete());
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();
        try {
            $saved->read($cancellation->getCancellation());
            self::fail('Expected cancellation');
        } catch (CancelledException) {
            self::assertTrue($saved->isClosed());
        }
    }

    public function testStopAndPublicEofWaitForSlowFileWrite(): void
    {
        $writing = new DeferredFuture();
        $file = $this->createMock(File::class);
        $file->method('write')->willReturnCallback(static fn () => $writing->getFuture()->await());
        $file->expects(self::once())->method('close');
        $this->file($file);
        $recording = $this->recording(new ReadableBuffer('video'));
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        $stop = async(static fn () => $saved->stop());
        $read = async(static fn () => $saved->read());
        delay(0);
        self::assertFalse($stop->isComplete());
        self::assertFalse($read->isComplete());
        self::assertFalse($saved->isClosed());
        $writing->complete();
        $stop->await();
        self::assertNull($read->await());
        self::assertTrue($saved->isClosed());
    }

    public function testWriteFailureClosesFileAndSourceAndIsReportedByStop(): void
    {
        $file = $this->createMock(File::class);
        $file->method('write')->willThrowException(new RuntimeException('disk full'));
        $file->expects(self::once())->method('close');
        $this->file($file);
        $source = new ReadableBuffer('video');
        $recording = $this->recording($source);
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        try {
            $saved->stop();
            self::fail('Expected disk write failure');
        } catch (RuntimeException $error) {
            self::assertSame('disk full', $error->getMessage());
            self::assertTrue($source->isClosed());
            self::assertTrue($saved->isClosed());
        }
    }

    public function testConnectionLossClosesFileAndIsReported(): void
    {
        $file = $this->createMock(File::class);
        $file->expects(self::once())->method('close');
        $this->file($file);
        $source = $this->createMock(ReadableStream::class);
        $source->method('read')->willThrowException(new RuntimeException('connection lost'));
        $source->expects(self::once())->method('close');
        $recording = $this->recording($source);
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        $this->expectExceptionMessage('connection lost');
        $saved->stop();
    }

    public function testStopFailureClosesSourceAndWaitsForFileCleanup(): void
    {
        $closed = new DeferredFuture();
        $file = $this->createMock(File::class);
        $file->expects(self::once())->method('close');
        $this->file($file);
        $source = $this->createMock(ReadableStream::class);
        $source->method('read')->willReturnCallback(static function () use ($closed): null {
            $closed->getFuture()->await();

            return null;
        });
        $source->method('close')->willReturnCallback(static function () use ($closed): void {
            if (!$closed->isComplete()) {
                $closed->complete();
            }
        });
        $recording = $this->getMockBuilder(ScreenRecording::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRemoteStream', 'stopRemote', '__destruct'])
            ->getMock();
        $recording->method('getRemoteStream')->willReturn($source);
        $recording->expects(self::once())->method('stopRemote')->willThrowException(new RuntimeException('stop failed'));
        $saved = ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => $recording);
        try {
            $saved->stop();
            self::fail('Expected stop failure');
        } catch (RuntimeException $error) {
            self::assertSame('stop failed', $error->getMessage());
            self::assertTrue($saved->isClosed());
        }
    }

    public function testFileOpenFailureDoesNotStartChrome(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('getStatus')->willReturn(['mode' => 0040000]);
        $driver->method('openFile')->willThrowException(new RuntimeException('file exists'));
        filesystem($driver);
        $this->expectExceptionMessage('Cannot open recording file: file exists');
        ScreenRecordingStream::start(['path' => 'video.mp4', 'overwrite' => false], static function (): never {
            self::fail('Chrome must not start when file open fails');
        });
    }

    public function testChromeStartFailureClosesFile(): void
    {
        $file = $this->createMock(File::class);
        $file->expects(self::once())->method('close');
        $this->file($file);
        $this->expectExceptionMessage('Chrome disconnected');
        ScreenRecordingStream::start(['path' => 'video.mp4'], static fn () => throw new RuntimeException('Chrome disconnected'));
    }

    public function testInvalidOptionsDoNotOpenOrTruncateFile(): void
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->expects(self::never())->method('openFile');
        filesystem($driver);
        $this->expectExceptionMessage('`frameRate` must be greater than 0.');
        ScreenRecordingStream::start(['path' => 'video.mp4', 'frameRate' => 0], static function (): never {
            self::fail('Invalid options must not start Chrome');
        });
    }
}
