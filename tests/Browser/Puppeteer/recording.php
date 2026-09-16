<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\Puppeteer\ScreenRecording;
use Nesk\Puphpeteer\Tests\Support\Shared\ProcessRunner;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

$browser = (new Puppeteer())->launch();
$directory = sys_get_temp_dir() . '/puphpeteer-recording-' . bin2hex(random_bytes(8));
$path = $directory . '/nested/video.mp4';
try {
    $page = $browser->newPage();
    $page->setContent('<style>@keyframes move {to {transform:translateX(300px)}} div {width:100px;height:100px;background:red;animation:move 1s infinite alternate}</style><div></div>');
    $recording = $page->record(['frameRate' => 10, 'maxWidth' => 640, 'maxHeight' => 480, 'audio' => false]);
    if (!$recording instanceof ScreenRecording) {
        throw new RuntimeException('Recording must have its typed PHP wrapper');
    }
    $beforeStop = async(static function () use ($recording): string {
        $bytes = '';
        while (strlen($bytes) < 100 && null !== ($chunk = $recording->read())) {
            $bytes .= $chunk;
        }

        return $bytes;
    });
    for ($attempt = 0; $attempt < 100 && !$beforeStop->isComplete(); ++$attempt) {
        delay(0.05);
    }
    if (!$beforeStop->isComplete()) {
        $recording->stop();
        throw new RuntimeException('Recording did not stream before stop');
    }
    $bytes = $beforeStop->await();
    $read = async(static fn (): string => buffer($recording));
    $recording->stop();
    $recording->stop();
    $bytes .= $read->await();
    if (strlen($bytes) < 100 || 'ftyp' !== substr($bytes, 4, 4)) {
        throw new RuntimeException('Recording is not a complete MP4 (' . strlen($bytes) . ' bytes): ' . bin2hex(substr($bytes, 0, 32)));
    }
    if (!$recording->isClosed()) {
        throw new RuntimeException('Recording stream did not close');
    }
    $saved = $page->record(['path' => $path, 'frameRate' => 10, 'overwrite' => false]);
    delay(0.2);
    $saved->stop();
    $savedBytes = buffer($saved);
    if ($savedBytes !== file_get_contents($path)) {
        throw new RuntimeException('Recording file differs from the Amp stream');
    }
    try {
        $page->record(['path' => $path, 'overwrite' => false]);
        throw new LogicException('Expected exclusive creation to fail');
    } catch (RuntimeException) {
        if ($savedBytes !== file_get_contents($path)) {
            throw new RuntimeException('Exclusive creation damaged the existing recording');
        }
    }
    try {
        $page->record(['path' => $path, 'frameRate' => 0]);
        throw new LogicException('Expected invalid recording options to fail');
    } catch (RuntimeException) {
        if ($savedBytes !== file_get_contents($path)) {
            throw new RuntimeException('Invalid options truncated the existing recording');
        }
    }
    $early = $page->record();
    delay(0.1);
    $early->close();
    $early->close();
    if (!$early->isClosed() || 42 !== $page->evaluate('6 * 7')) {
        throw new RuntimeException('Early close damaged the browser connection');
    }
    echo 'Live recording, MP4 output, backpressure, overwrite protection and early close PASS', PHP_EOL;
} finally {
    $browser->close();
    if (is_dir($directory)) {
        ProcessRunner::removeDirectory($directory);
    }
}
