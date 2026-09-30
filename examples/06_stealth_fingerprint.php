<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\Puppeteer\Page;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

// The module root must contain node_modules with both plugins.
// In this repository run `npm ci`; in your application run
// `npm install puppeteer-extra-plugin-stealth fingerprint-injector` from its root.
$runtime = new JsRuntime(moduleRoot: dirname(__DIR__));
$stealth = $runtime->require('puppeteer-extra-plugin-stealth');
['newInjectedPage' => $newInjectedPage] = $runtime->require('fingerprint-injector');

$puppeteer = new Puppeteer(runtime: $runtime);
$puppeteer->use($stealth());
$browser = $puppeteer->launch(['headless' => true]);

try {
    $page = $runtime->run(new JsFunction(<<<'JS'
        (newInjectedPage, browser) => newInjectedPage(browser, {
            fingerprintOptions: {devices: ['mobile'], operatingSystems: ['ios']},
        })
    JS), $newInjectedPage, $browser);
    if (!$page instanceof Page) {
        throw new RuntimeException('Fingerprint injector did not return a Page');
    }

    $page->goto('data:text/html,<title>Stealth and fingerprint</title>');
    $navigator = $page->evaluate(new JsFunction('() => ({userAgent: navigator.userAgent, webdriver: navigator.webdriver})'));
    if (!is_array($navigator) || !is_string($navigator['userAgent'] ?? null)) {
        throw new RuntimeException('Could not read the injected navigator properties');
    }

    printf("User-Agent: %s\n", $navigator['userAgent']);
    printf("navigator.webdriver: %s\n", var_export($navigator['webdriver'], true));
} finally {
    $browser->close();
}
