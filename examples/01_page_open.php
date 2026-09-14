<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\JsFunction;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;

$puppeteer = new Puppeteer();

// Add --headful on a machine with a graphical session to use headless => false.
$browser = $puppeteer->launch([
    'headless' => !in_array('--headful', $argv, true),
    'defaultViewport' => ['width' => 1280, 'height' => 720],
    'timeout' => 30_000,
]);
$page = $browser->newPage();
$page->goto('file://' . __DIR__ . '/pages/index.html');

// Get the "viewport" of the page, as reported by the page.
$dimensions = $page->evaluate(JsFunction::createWithBody(/* @lang JavaScript */ '
    return {
        width: document.documentElement.clientWidth,
        height: document.documentElement.clientHeight,
        deviceScaleFactor: window.devicePixelRatio
    };
'));

printf('Dimensions: %s', print_r($dimensions, true));

$browser->close();
