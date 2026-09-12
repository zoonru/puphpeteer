<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\HTTPRequest;
use Nesk\Puphpeteer\Puppeteer;
use function Amp\async;

$url = getenv('FORM_EXAMPLE_URL') ?: 'file://' . __DIR__ . '/pages/form.html';
$browser = (new Puppeteer())->launch();
try {
    $page = $browser->newPage();
    $page->goto($url);
    $page->setRequestInterception(true);
    $page->on('request', static function (HTTPRequest $request): void {
        if (str_contains($request->url(), 'example.invalid/form-submit')) {
            echo 'Form request: ', $request->method(), ' ', $request->url(), PHP_EOL;
            echo 'Form data: ', $request->postData() ?? '', PHP_EOL;
            $request->abort('blockedbyclient');
            return;
        }
        $request->continue();
    });

    $requestFuture = async(static function () use ($page): HTTPRequest {
        return $page->waitForRequest(
            static fn(HTTPRequest $request): bool => $request->isNavigationRequest()
                && str_contains($request->url(), 'example.invalid/form-submit')
                && $request->method() === 'POST',
            ['timeout' => 10_000],
        );
    });

    $page->click('#submit');
    $request = $requestFuture->await(new TimeoutCancellation(10));
    echo 'Intercepted form URL: ', $request->url(), PHP_EOL;
} finally {
    $browser->close();
}
