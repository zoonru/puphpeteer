<?php

require __DIR__ . '/../vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;

$puppeteer = new Puppeteer;
$browser = $puppeteer->launch();

$page = $browser->newPage();
$page->setViewport(['width' => 1366, 'height' => 768]);
$page->goto(getenv('EXAMPLE_URL') ?: 'https://example.com');
$page->screenshot(['path' => 'example.png']);

$browser->close();