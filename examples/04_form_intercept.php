<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\Puppeteer\HTTPRequest;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

use function Amp\async;

$url = 'file://' . __DIR__ . '/pages/form.html';
$browser = (new Puppeteer())->launch();
try {
    $page = $browser->newPage();
    $page->goto($url);
    $page->setRequestInterception(true);
    $page->on('request', static function (HTTPRequest $request): void {
        if (str_contains($request->url(), 'example.invalid/form-submit')) {
            $request->abort('blockedbyclient');

            return;
        }
        $request->continue();
    });

    $result = [];
    $requestFuture = async(static function () use ($page, &$result): HTTPRequest {
        return $page->waitForRequest(
            function (HTTPRequest $request) use (&$result): bool {
                $isMatch = $request->isNavigationRequest()
                    && str_contains($request->url(), 'example.invalid/form-submit')
                    && 'POST' === $request->method();

                if ($isMatch) {
                    $result = [
                        'method' => $request->method(),
                        'url' => $request->url(),
                        'form_data' => $request->postData(),
                    ];
                }

                return $isMatch;
            },
            ['timeout' => 10_000],
        );
    });

    $page->click('#submit');
    $request = $requestFuture->await(new TimeoutCancellation(10));
    echo 'Intercepted form URL: ', $request->url(), PHP_EOL;
    var_dump($result);
} finally {
    $browser->close();
}
