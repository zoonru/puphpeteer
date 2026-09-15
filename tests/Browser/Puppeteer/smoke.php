<?php

declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\JsFunction as JS;
use Nesk\Puphpeteer\Puppeteer\Browser;
use Nesk\Puphpeteer\Puppeteer\ElementHandle;
use Nesk\Puphpeteer\Puppeteer\Frame;
use Nesk\Puphpeteer\Puppeteer\JSHandle;
use Nesk\Puphpeteer\Puppeteer\Keyboard;
use Nesk\Puphpeteer\Puppeteer\Mouse;
use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\Resources\ConsoleMessage;
use Nesk\Puphpeteer\Resources\Page;

use function Amp\async;
use function Amp\Future\await;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$puppeteer = new Puppeteer();
$fixture = 'file://' . dirname(__DIR__, 3) . '/examples/pages/index.html';
$launched = $puppeteer->launch(['headless' => true, 'args' => ['--no-proxy-server']]);
try {
    $browser = $puppeteer->connect(['browserWSEndpoint' => $launched->wsEndpoint(), 'defaultViewport' => null]);
    $context = $browser->createBrowserContext();
    try {
        check($browser instanceof Browser && $browser->connected, 'Typed connected Browser');
        $pages = await([async(fn () => $context->newPage()), async(fn () => $context->newPage())]);
        check($pages[0] instanceof Page && $pages[1] instanceof Page, 'Typed pages and aliases');
        check($pages[0]->browser() === $browser && $pages[0]->browserContext() === $context, 'Object identity');
        await(array_map(static function ($page) use ($fixture) {
            return async(static function () use ($page, $fixture): void { $page->goto($fixture); });
        }, $pages));
        $page = $pages[0];
        check('PuPHPeteer example' === $page->title(), 'Navigation/title');
        $style = $page->addStyleTag(['content' => ':root { --bridge-style: page; }']);
        check($style instanceof ElementHandle && 'STYLE' === $style->evaluate(new JS('element => element.tagName')), 'Page.addStyleTag content overload');
        $link = $page->mainFrame()->addStyleTag(['url' => 'data:text/css,:root{--bridge-style:frame}']);
        check($link instanceof ElementHandle && 'LINK' === $link->evaluate(new JS('element => element.tagName')), 'Frame.addStyleTag URL overload');
        check('frame' === $page->evaluate('getComputedStyle(document.documentElement).getPropertyValue("--bridge-style").trim()'), 'Injected stylesheet applied');

        check(42 === $page->evaluate(new JS('(a,b) => a+b'), 20, 22), 'Raw JsFunction');
        check('PuPHPeteer example' === $page->evaluate(JS::createWithBody('return document.title;')), 'Old JsFunction factory');
        check(42 === $page->evaluate(JS::createWithParameters(['a', 'b' => 2])->scope(['offset' => 3])->body('return a+b+offset;')->async(), 37), 'Factory defaults/scope/async');
        $order = [];
        $start = hrtime(true);
        $slow = async(function () use ($page, &$order) {
            $value = $page->evaluate('new Promise(r=>setTimeout(()=>r("slow"),250))');
            $order[] = $value;

            return $value;
        });
        $fast = async(function () use ($pages, &$order) {
            $value = $pages[1]->evaluate('new Promise(r=>setTimeout(()=>r("fast"),30))');
            $order[] = $value;

            return $value;
        });
        $results = await([$slow, $fast]);
        check('slow' === $results[0] && 'fast' === $results[1] && $order === ['fast', 'slow'], 'Concurrent automatic awaiting: ' . json_encode([$results, $order]));
        $elapsed = (hrtime(true) - $start) / 1e6;
        echo "parallel PASS\n";
        $page->exposeFunction('phpDouble', function ($n) {
            Amp\delay(0.025);

            return $n * 2;
        });
        check(42 === $page->evaluate(new JS('() => window.phpDouble(21)')), 'PHP callback can suspend');
        $page->exposeFunction('phpReject', function () { throw new RuntimeException('PHP rejected'); });
        check('PHP rejected' === $page->evaluate(new JS('() => window.phpReject().catch(e=>e.message)')), 'Callback exception');

        echo "callbacks PASS\n";
        $handle = $page->evaluateHandle(new JS('() => ({answer:42})'));
        check($handle instanceof JSHandle && $handle->jsonValue() === ['answer' => 42], 'JSHandle');
        check(is_string($handle->id), 'Upstream id property does not expose transport id');
        $handle->dispose();
        $handle->release();
        $element = $page->querySelector('body');
        check($element instanceof ElementHandle && $element instanceof JSHandle, 'Selection and inheritance');
        check('PuPHPeteer example' === $page->querySelectorEval('title', new JS('element => element.textContent')), 'JS alias');
        $element->dispose();
        $element->release();
        check($page->mainFrame() instanceof Frame, 'Frame');
        check($page->keyboard instanceof Keyboard && $page->keyboard === $page->keyboard, 'Keyboard property identity');
        check($page->mouse instanceof Mouse, 'Mouse property');
        $page->keyboard->press('Escape');
        try {
            $page->evaluate(new JS('()=>{throw new Error("expected failure")}'));
            throw new LogicException('Missing error');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'expected failure'), 'Direct exception');
        }
        check('PuPHPeteer example' === $page->title(), 'Recovery');
        $page->bringToFront();
        $page->click('#button');
        check('clicked' === $page->evaluate('document.querySelector("#result").textContent'), 'Click');

        echo "handles and properties PASS\n";
        $received = new DeferredFuture();
        $listener = function (ConsoleMessage $message) use ($page, $received) {
            if ('event works' === $message->text() && !$received->isComplete()) {
                $received->complete($page->title());
            }
        };
        check($page->on('console', $listener) === $page, 'on returns receiver');
        $page->evaluate('console.log("event works")');
        check('PuPHPeteer example' === $received->getFuture()->await(new TimeoutCancellation(2)), 'Callback reentry');
        $page->off('console', $listener);
        check(0 === $page->listenerCount('console'), 'PHP handler identity');
        $jsListener = new JS('message => undefined');
        $page->on('console', $jsListener);
        $page->off('console', $jsListener);
        check(0 === $page->listenerCount('console'), 'JS handler identity');
        $once = new DeferredFuture();
        $page->once('console', function () use ($once) {
            if (!$once->isComplete()) {
                $once->complete(true);
            }
        });
        $page->evaluate('console.log("once")');
        check($once->getFuture()->await(new TimeoutCancellation(2)) && 0 === $page->listenerCount('console'), 'once');

        echo "events PASS\n";
        $path = sys_get_temp_dir() . '/puphpeteer-smoke-' . bin2hex(random_bytes(5)) . '.png';
        try {
            $png = $page->screenshot(['path' => $path]);
            check(str_starts_with($png, "\x89PNG\r\n\x1a\n") && file_get_contents($path) === $png, 'Screenshot file');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
        echo "connect, properties, functions, events, concurrency and screenshot PASS\n";
    } finally {
        $context->close();
        $browser->disconnect();
    }
    // Exercise PHP-only launch with the same public API as old examples.
    $page = $launched->newPage();
    $page->goto($fixture);
    check('PuPHPeteer example' === $page->title(), 'PHP launch');
    $endpoint = $launched->wsEndpoint();
    $parts = parse_url($endpoint);
    $second = (new Puppeteer())->connect(['browserURL' => 'http://' . $parts['host'] . ':' . $parts['port']]);
    check($second->connected, 'browserURL');
    $second->disconnect();
} finally {
    $launched->close();
}
echo json_encode(['status' => 'PASS', 'parallel_ms' => round($elapsed, 2)], JSON_PRETTY_PRINT), "\n";
