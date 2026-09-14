<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use function Amp\ByteStream\buffer;

$browser = (new Puppeteer())->launch();
$file = tempnam(sys_get_temp_dir(), 'puphpeteer-stream-pdf-');
if ($file === false) { throw new RuntimeException('Cannot create PDF fixture'); }
try {
    $page = $browser->newPage();
    $page->setContent('<h1>Streaming PDF</h1>' . str_repeat('<p>PuPHPeteer stream test</p>', 100));
    $stream = $page->createPDFStream();
    $pdf = '';
    try {
        while (($chunk = $stream->read()) !== null) {
            if (strlen($chunk) > 65536) { throw new RuntimeException('Oversized stream chunk'); }
            $pdf .= $chunk;
        }
    } finally { $stream->close(); }
    if (!str_starts_with($pdf, '%PDF-') || !str_contains(substr($pdf, -1024), '%%EOF')) {
        throw new RuntimeException('Incomplete streamed PDF');
    }
    // Cancellation before reading must also close the already-created CDP handle.
    $early = $page->createPDFStream();
    $early->close();
    Amp\delay(0.01);
    if ($page->evaluate('6 * 7') !== 42) { throw new RuntimeException('Stream close damaged browser connection'); }
    if (!str_starts_with(buffer($page->createPDFStream()), '%PDF-')) { throw new RuntimeException('Amp buffer failed'); }
    $saved = $page->pdf(['path' => $file]);
    if ($saved !== file_get_contents($file)) { throw new RuntimeException('PDF file differs from returned bytes'); }
    echo 'PDF streaming, early close, Amp buffer and PDF file output PASS', PHP_EOL;
} finally { unlink($file); $browser->close(); }
