<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\JsFunction;
use function Amp\async;

function verify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$browser = (new Puppeteer())->connect(['browserWSEndpoint' => getenv('BROWSER_WS'), 'protocolTimeout' => 1000]);
$context = $browser->createBrowserContext();
try {
    $page = $context->newPage();
    $data = ['$quickjs' => 'object', 'id' => 123, 'nested' => ['$quickjs' => 'undefined']];
    verify($page->evaluate(new JsFunction('value => value'), $data) === $data, 'Protocol-looking data corrupted');
    $calls = 0;
    $callback = function () use ($page, &$calls): void { verify($page->title() === '', 'Reentrant callback failed'); ++$calls; };
    $page->on('console', $callback);
    $page->evaluate('console.log("one")');
    Amp\delay(0.05);
    $page->off('console', $callback);
    $page->evaluate('console.log("two")');
    Amp\delay(0.05);
    verify($calls === 1, 'off did not preserve callback identity');
    $other = $context->newPage();
    $handler = static function (): void {};
    $weak = WeakReference::create($handler);
    $page->on('console', $handler);
    $other->on('console', $handler);
    $page->off('console', $handler);
    verify($weak->get() !== null, 'Handler released while registered on second emitter');
    $other->off('console', $handler);
    unset($handler);
    gc_collect_cycles();
    verify($weak->get() === null, 'off retained PHP handler');
    $once = 0;
    $handler = static function () use (&$once): void { ++$once; };
    $weak = WeakReference::create($handler);
    $page->once('console', $handler);
    unset($handler);
    $page->evaluate('console.log("once"); console.log("twice")');
    Amp\delay(0.02);
    gc_collect_cycles();
    verify($once === 1 && $weak->get() === null, 'once handler not released');
    $other->close();
    try { $page->waitForSelector('#never', ['timeout' => 20]); throw new LogicException('Timeout did not occur'); }
    catch (RuntimeException $error) { verify(str_contains($error->getMessage(), 'Timeout'), 'Unexpected timeout error'); }
    verify($page->evaluate('6 * 7') === 42, 'Timeout damaged transport');
    $pending = async(fn() => $page->evaluate('new Promise(() => {})'));
    Amp\delay(0.02);
    $page->close();
    try { $pending->await(); throw new LogicException('Closed page retained pending operation'); }
    catch (RuntimeException $error) { verify(str_contains(strtolower($error->getMessage()), 'closed'), 'Unexpected page close error'); }
    verify($context->newPage()->evaluate('42') === 42, 'Page close damaged browser');
    echo "runtime lifecycle PASS\n";
} finally { $context->close(); $browser->disconnect(); }
