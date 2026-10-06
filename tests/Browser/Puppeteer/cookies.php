<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

$puppeteer = new Puppeteer();
$endpoint = getenv('BROWSER_WS');
$browser = $endpoint ? $puppeteer->connect(['browserWSEndpoint' => $endpoint]) : $puppeteer->launch(['headless' => true]);
try {
    $context = $browser->createBrowserContext();
    $page = $context->newPage();
    $page->setRequestInterception(true);
    $page->on('request', new JsFunction('async request => request.respond({status: 200, contentType: "text/html", body: "<title>Cookies</title>"})'));
    $page->setExtraHTTPHeaders(['X-Host-Api' => 'original']);
    $url = 'http://127.0.0.1/puphpeteer-cookies';
    $response = $page->goto($url, ['waitUntil' => 'domcontentloaded']);
    if (null === $response) {
        throw new RuntimeException('Missing HTTP response');
    }
    $request = $response->request();
    $headers = $request->headers();
    if ('original' !== ($headers['x-host-api'] ?? null)) {
        throw new RuntimeException('Reading HTTP request headers failed');
    }
    $headers['x-host-api'] = 'changed';
    if ('original' !== ($request->headers()['x-host-api'] ?? null)) {
        throw new RuntimeException('HTTP request headers were mutated');
    }
    foreach (['first', 'replacement'] as $value) {
        $page->setCookie(['name' => 'host-url-test', 'value' => $value, 'url' => $url]);
        $cookies = $page->cookies();
        if (1 !== count($cookies) || $value !== $cookies[0]['value']) {
            throw new RuntimeException('Setting or replacing cookies on an HTTP page failed');
        }
    }
    $page->deleteCookie(['name' => 'host-url-test', 'url' => $url]);
    if ([] !== $page->cookies()) {
        throw new RuntimeException('Deleting cookies on an HTTP page failed');
    }
    $context->close();
    echo "HTTP request headers and cookie creation, replacement and deletion PASS\n";
} finally {
    $browser->close();
}
