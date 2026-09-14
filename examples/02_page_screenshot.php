<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\JsFunction;

$puppeteer = new Puppeteer;
// Plugins run in QuickJS; enable only the evasions needed by this example.
$puppeteer->use('stealth', ['enabledEvasions' => [
    'navigator.webdriver',
    'navigator.languages',
]]);
$puppeteer->use('stealth/evasions/navigator.hardwareConcurrency', [
    'hardwareConcurrency' => 8,
]);
$browser = $puppeteer->launch([
    'headless' => true,
    'args' => ['--user-agent=PuPHPeteer-Example/1.0', '--lang=en-US'],
    'defaultViewport' => ['width' => 1366, 'height' => 768, 'deviceScaleFactor' => 2],
]);

$page = $browser->newPage();
echo 'User-Agent: ', $page->evaluate(new JsFunction('() => navigator.userAgent')), PHP_EOL;
$page->goto('file://' . __DIR__ . '/pages/index.html');
$page->screenshot(['path' => 'example.png']);

$browser->close();
