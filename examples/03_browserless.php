<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;

// Start Browserless separately and provide its complete endpoint, including token.
$endpoint = getenv('BROWSER_WS') ?: throw new RuntimeException('Set BROWSER_WS');
$browser = (new Puppeteer())->connect([
    'browserWSEndpoint' => $endpoint,
    'defaultViewport' => ['width' => 1280, 'height' => 720],
    'protocolTimeout' => 60_000, // Per CDP call; Browserless TIMEOUT limits the whole session.
]);
$context = $browser->createBrowserContext();
try {
    $page = $context->newPage();
    $page->goto('https://example.com', ['timeout' => 30_000, 'waitUntil' => 'domcontentloaded']);
    print_r($page->evaluate(JsFunction::createWithBody('return {title: document.title, width: innerWidth};')));
} finally {
    $context->close();
    $browser->disconnect();
}
