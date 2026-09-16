<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\ByteStream\WritableResourceStream;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use RuntimeException;

use function Amp\async;
use function Amp\ByteStream\pipe;
use function Amp\delay;

$path = 'recording.mp4';
$resource = fopen($path, 'wb');
if (false === $resource) {
    throw new RuntimeException('Cannot open recording output');
}

$output = new WritableResourceStream($resource);
$browser = (new Puppeteer())->launch();
$recording = null;
try {
    $page = $browser->newPage();
    $page->setContent('<style>@keyframes move {to {transform:translateX(500px)}} div {width:100px;height:100px;background:red;animation:move 1s infinite alternate}</style><div></div>');
    $recording = $page->record(['frameRate' => 30]);
    $copy = async(static fn (): int => pipe($recording, $output));

    delay(2);
    $recording->stop();
    $bytes = $copy->await();
    $output->end();

    printf("Streamed %d bytes to %s\n", $bytes, realpath($path));
} finally {
    $recording?->close();
    $output->close();
    $browser->close();
}
