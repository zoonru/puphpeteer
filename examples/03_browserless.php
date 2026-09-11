<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;

// Start Browserless separately and provide its complete endpoint, including token.
$endpoint = getenv('BROWSER_WS') ?: throw new RuntimeException('Set BROWSER_WS');
$browser = (new Puppeteer())->connect(['browserWSEndpoint' => $endpoint]);
$context = $browser->createBrowserContext();
try {
    $page = $context->newPage();
    $page->goto(getenv('EXAMPLE_URL') ?: 'https://example.com');
    print_r($page->evaluate(JsFunction::createWithBody('return {title: document.title, width: innerWidth};')));
} finally {
    $context->close();
    $browser->disconnect();
}
