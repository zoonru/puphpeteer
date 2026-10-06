<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\JsRuntime;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

use function Amp\File\getSize;

// Install the plugin with npm in the module root; this repository uses `npm ci`.
$runtime = new JsRuntime(moduleRoot: dirname(__DIR__));
$stealth = $runtime->require('puppeteer-extra-plugin-stealth');
$hardwareConcurrency = $runtime->require('puppeteer-extra-plugin-stealth/evasions/navigator.hardwareConcurrency');
$puppeteer = new Puppeteer(runtime: $runtime);
$puppeteer->use($stealth(['enabledEvasions' => [
    'navigator.webdriver',
    'navigator.languages',
]]));
$puppeteer->use($hardwareConcurrency(['hardwareConcurrency' => 8]));
$browser = $puppeteer->launch([
    'headless' => true,
    'args' => ['--user-agent=PuPHPeteer-Example/1.0', '--lang=en-US'],
    'defaultViewport' => ['width' => 1366, 'height' => 768, 'deviceScaleFactor' => 2],
]);

try {
    $page = $browser->newPage();
    echo 'User-Agent: ', $page->evaluate(new JsFunction('() => navigator.userAgent')), PHP_EOL;
    $page->goto('file://' . __DIR__ . '/pages/index.html');
    $page->evaluate(new JsFunction(<<<'JS'
        () => {
            const state = {
                webdriver: String(navigator.webdriver),
                hardwareConcurrency: navigator.hardwareConcurrency,
            };
            const passed = ['false', 'undefined'].includes(state.webdriver) && state.hardwareConcurrency === 8;
            const panel = document.createElement('pre');
            panel.style.cssText = `position:fixed;top:16px;right:16px;z-index:2147483647;margin:0;padding:16px;border:2px solid ${passed ? '#16a34a' : '#dc2626'};border-radius:8px;background:#fff;color:#111;font:16px/1.5 monospace;white-space:pre-wrap`;
            panel.textContent = `Stealth checks: ${passed ? 'PASSED' : 'FAILED'}\nnavigator.webdriver = ${state.webdriver}\nnavigator.hardwareConcurrency = ${state.hardwareConcurrency} (expected: 8)`;
            document.body.append(panel);
        }
        JS));

    $start = microtime(true);
    $path = 'example.png';
    $page->screenshot(['path' => $path]);
    printf('Screenshot saved. Path: %s; Size: %s bytes; Duration: %s ms; %s', $path, getSize($path), round((microtime(true) - $start) * 1000, 3), PHP_EOL);
} finally {
    $browser->close();
}
