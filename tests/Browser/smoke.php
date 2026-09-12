<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction as JS;
use Nesk\Puphpeteer\Resources\Page;
use Nesk\Puphpeteer\Resources\ConsoleMessage;
use function Amp\async;
use function Amp\Future\await;
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$puppeteer = new Puppeteer();
$fixture = 'file://' . dirname(__DIR__, 2) . '/examples/pages/index.html';
$launched = $puppeteer->launch(['headless' => true, 'args' => ['--no-proxy-server']]);
try {
    $browser = $puppeteer->connect(['browserWSEndpoint' => $launched->wsEndpoint(), 'defaultViewport' => null]);
    $context = $browser->createBrowserContext();
    try {
    check($browser instanceof Nesk\Puphpeteer\Browser && $browser->connected, 'Typed connected Browser');
    $pages = await([async(fn() => $context->newPage()), async(fn() => $context->newPage())]);
    check($pages[0] instanceof Page && $pages[1] instanceof Page, 'Typed pages and aliases');
    check($pages[0]->browser() === $browser && $pages[0]->browserContext() === $context, 'Object identity');
    await(array_map(static function ($page) use ($fixture) {
        return async(static function () use ($page, $fixture): void { $page->goto($fixture); });
    }, $pages));
    $page = $pages[0];
    check($page->title() === 'PuPHPeteer example', 'Navigation/title');
    check($page->evaluate(new JS('(a,b) => a+b'), 20, 22) === 42, 'Raw JsFunction');
    check($page->evaluate(JS::createWithBody('return document.title;')) === 'PuPHPeteer example', 'Old JsFunction factory');
    check($page->evaluate(JS::createWithParameters(['a', 'b' => 2])->scope(['offset' => 3])->body('return a+b+offset;')->async(), 37) === 42, 'Factory defaults/scope/async');
    $order = [];
    $start = hrtime(true);
    $slow = async(function () use ($page, &$order) { $value = $page->evaluate('new Promise(r=>setTimeout(()=>r("slow"),250))'); $order[]=$value; return $value; });
    $fast = async(function () use ($pages, &$order) { $value = $pages[1]->evaluate('new Promise(r=>setTimeout(()=>r("fast"),30))'); $order[]=$value; return $value; });
    $results = await([$slow, $fast]);
    check($results[0] === 'slow' && $results[1] === 'fast' && $order === ['fast', 'slow'], 'Concurrent automatic awaiting: ' . json_encode([$results, $order]));
    $elapsed = (hrtime(true) - $start) / 1e6;
    echo "parallel PASS\n";
    $page->exposeFunction('phpDouble', function ($n) { Amp\delay(0.025); return $n*2; });
    check($page->evaluate(new JS('() => window.phpDouble(21)')) === 42, 'PHP callback can suspend');
    $page->exposeFunction('phpReject', function () { throw new RuntimeException('PHP rejected'); });
    check($page->evaluate(new JS('() => window.phpReject().catch(e=>e.message)')) === 'PHP rejected', 'Callback exception');

    echo "callbacks PASS\n";
    $handle = $page->evaluateHandle(new JS('() => ({answer:42})'));
    check($handle instanceof Nesk\Puphpeteer\JSHandle && $handle->jsonValue() === ['answer'=>42], 'JSHandle');
    check(is_string($handle->id), 'Upstream id property does not expose transport id');
    $handle->dispose(); $handle->release();
    $element = $page->querySelector('body');
    check($element instanceof Nesk\Puphpeteer\ElementHandle && $element instanceof Nesk\Puphpeteer\JSHandle, 'Selection and inheritance');
    check($page->querySelectorEval('title', new JS('element => element.textContent')) === 'PuPHPeteer example', 'JS alias');
    $element->dispose(); $element->release();
    check($page->mainFrame() instanceof Nesk\Puphpeteer\Frame, 'Frame');
    check($page->keyboard instanceof Nesk\Puphpeteer\Keyboard && $page->keyboard === $page->keyboard, 'Keyboard property identity');
    check($page->mouse instanceof Nesk\Puphpeteer\Mouse, 'Mouse property');
    $page->keyboard->press('Escape');
    try { $page->evaluate(new JS('()=>{throw new Error("expected failure")}')); throw new LogicException('Missing error'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'expected failure'), 'Direct exception'); }
    check($page->title() === 'PuPHPeteer example', 'Recovery');
    $page->bringToFront();
    $page->click('#button');
    check($page->evaluate('document.querySelector("#result").textContent') === 'clicked', 'Click');

    echo "handles and properties PASS\n";
    $received = new Amp\DeferredFuture();
    $listener = function (ConsoleMessage $message) use ($page, $received) {
        if ($message->text() === 'event works' && !$received->isComplete()) { $received->complete($page->title()); }
    };
    check($page->on('console', $listener) === $page, 'on returns receiver');
    $page->evaluate('console.log("event works")');
    check($received->getFuture()->await(new Amp\TimeoutCancellation(2)) === 'PuPHPeteer example', 'Callback reentry');
    $page->off('console', $listener);
    check($page->listenerCount('console') === 0, 'PHP handler identity');
    $jsListener = new JS('message => undefined');
    $page->on('console', $jsListener); $page->off('console', $jsListener);
    check($page->listenerCount('console') === 0, 'JS handler identity');
    $once = new Amp\DeferredFuture();
    $page->once('console', function () use ($once) { if (!$once->isComplete()) $once->complete(true); });
    $page->evaluate('console.log("once")');
    check($once->getFuture()->await(new Amp\TimeoutCancellation(2)) && $page->listenerCount('console') === 0, 'once');

    echo "events PASS\n";
    $path = sys_get_temp_dir() . '/puphpeteer-smoke-' . bin2hex(random_bytes(5)) . '.png';
    try {
        $png = $page->screenshot(['path'=>$path]);
        check(str_starts_with($png, "\x89PNG\r\n\x1a\n") && file_get_contents($path) === $png, 'Screenshot file');
    } finally { if (is_file($path)) unlink($path); }
    echo "connect, properties, functions, events, concurrency and screenshot PASS\n";
    } finally {
        $context->close();
        $browser->disconnect();
    }
    // Exercise PHP-only launch with the same public API as old examples.
    $page = $launched->newPage();
    $page->goto($fixture);
    check($page->title() === 'PuPHPeteer example', 'PHP launch');
    $endpoint = $launched->wsEndpoint();
    $parts = parse_url($endpoint);
    $second = (new Puppeteer())->connect(['browserURL'=>'http://' . $parts['host'] . ':' . $parts['port']]);
    check($second->connected, 'browserURL');
    $second->disconnect();
} finally { $launched->close(); }
echo json_encode(['status'=>'PASS', 'parallel_ms'=>round($elapsed,2)], JSON_PRETTY_PRINT), "\n";
