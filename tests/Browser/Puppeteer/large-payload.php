<?php

declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\Tests\Support\Puppeteer\LargePayload;

use function Amp\async;
use function Amp\Future\await;

$browser = (new Puppeteer())->launch();
try {
    $page = $browser->newPage();
    foreach ([2097151, 2097153, 4194321, 8388639, 12582943] as $size) {
        LargePayload::preparePage($page, $size);
        $expected = LargePayload::expected($size);
        LargePayload::verify(LargePayload::digest($page->content()), LargePayload::HTML_START . $expected . LargePayload::HTML_END, 'HTML ' . $size);
        $fn = new JsFunction('(suffix) => globalThis.__payloadText + suffix');
        LargePayload::verify(LargePayload::digest($page->evaluate($fn, LargePayload::SUFFIX)), $expected . LargePayload::SUFFIX, 'JsFunction ' . $size);
        // Several in-flight large results must not overwrite each other.
        foreach (await([async(fn () => $page->evaluate($fn, 'A')), async(fn () => $page->evaluate($fn, 'B'))]) as $i => $value) {
            LargePayload::verify(LargePayload::digest($value), $expected . (0 === $i ? 'A' : 'B'), 'Concurrent result ' . $size);
        }
        if (42 !== $page->evaluate('6 * 7')) {
            throw new RuntimeException('Large result damaged connection');
        }
        echo 'HTML and JsFunction ', $size, ' bytes: length + SHA-256 PASS', PHP_EOL;
    }
} finally {
    $browser->close();
}
