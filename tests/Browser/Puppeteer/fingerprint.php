<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\Puppeteer\Page;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

$root = dirname(__DIR__, 3);
$runtime = new JsRuntime(moduleRoot: $root);
$newInjectedPage = $runtime->require('fingerprint-injector')['newInjectedPage'];
$browser = (new Puppeteer(runtime: $runtime))->launch(['headless' => true]);
try {
    $page = $newInjectedPage($browser, ['fingerprintOptions' => ['devices' => ['mobile'], 'operatingSystems' => ['ios']]]);
    if (!$page instanceof Page) {
        throw new RuntimeException('Fingerprint injector did not return a Page');
    }
    $page->goto('data:text/html,<title>fingerprint</title>');
    $userAgent = $page->evaluate(new JsFunction('() => navigator.userAgent'));
    if (!is_string($userAgent) || !str_contains($userAgent, 'iPhone') || !str_contains($userAgent, 'Mobile/')) {
        throw new RuntimeException('Fingerprint user agent was not applied: ' . var_export($userAgent, true));
    }
    echo "Fingerprint page PASS\n";
} finally {
    $browser->close();
}
