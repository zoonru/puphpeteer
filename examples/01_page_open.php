<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\JsFunction;

$puppeteer = new Puppeteer;

$browser = $puppeteer->launch();
$page = $browser->newPage();
$page->goto(getenv('EXAMPLE_URL') ?: 'https://example.com');

// Get the "viewport" of the page, as reported by the page.
$dimensions = $page->evaluate(JsFunction::createWithBody(/** @lang JavaScript */"
    return {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
        deviceScaleFactor: window.devicePixelRatio
    };
"));

printf('Dimensions: %s', print_r($dimensions, true));

$browser->close();