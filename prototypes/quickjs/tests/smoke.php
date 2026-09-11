<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use PuphpeteerQuickJs\Client;
use PuphpeteerQuickJs\JavaScriptFunction as JS;
use function Amp\async;
use function Amp\Future\await;
function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
$client = new Client();
try {
    $browser = $client->connect(getenv('BROWSER_WS'))->await();
    echo "connected\n";
    $context = $browser->createBrowserContext()->await();
    $pages = await([$context->newPage(), $context->newPage()]);
    echo "pages created\n";
    await(array_map(fn($page) => $page->goto(getenv('FIXTURE_URL')), $pages));
    check($pages[0]->title()->await() === 'QuickJS fixture', 'Navigation/title mismatch');
    check($pages[0]->evaluate(new JS('(a, b) => a + b'), 20, 22)->await() === 42, 'Function arguments');
    echo "navigation and evaluate passed\n";
    $order = [];
    $start = hrtime(true);
    $slow = $pages[0]->evaluate(new JS('() => new Promise(r => setTimeout(() => r("slow"), 250))'))->map(function($value) use (&$order) { $order[] = $value; return $value; });
    $fast = $pages[1]->evaluate(new JS('() => new Promise(r => setTimeout(() => r("fast"), 30))'))->map(function($value) use (&$order) { $order[] = $value; return $value; });
    $results = await([$slow, $fast]);
    check($results[0] === 'slow' && $results[1] === 'fast', 'Concurrent results: ' . json_encode($results));
    check($order === ['fast', 'slow'], 'Operations serialized');
    $elapsed = (hrtime(true) - $start) / 1e6;
    $pages[0]->exposeFunction('phpDouble', fn($n) => async(function () use ($n) { Amp\delay(0.025); return $n * 2; }))->await();
    check($pages[0]->evaluate(new JS('() => window.phpDouble(21)'))->await() === 42, 'Async PHP callback');
    echo "concurrency and async PHP callback passed\n";
    $handle = $pages[0]->evaluateHandle(new JS('() => ({answer: 42})'))->await();
    check($handle->jsonValue()->await() === ['answer' => 42], 'Object handles');
    $handle->dispose()->await();
    $handle->release();
    try { $pages[0]->evaluate(new JS('() => { throw new Error("expected failure"); }'))->await(); throw new LogicException('Missing JS error'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'expected failure'), 'Wrong JS error'); }
    check($pages[0]->title()->await() === 'QuickJS fixture', 'Error recovery');
    $pages[0]->bringToFront()->await();
    $pages[0]->click('#button')->await();
    check($pages[0]->evaluate('document.querySelector("#result").textContent')->await() === 'clicked', 'Click');
    $pages[0]->exposeFunction('phpReject', fn() => async(function () { Amp\delay(0.005); throw new RuntimeException('PHP rejected'); }))->await();
    check($pages[0]->evaluate(new JS('() => window.phpReject().catch(e => e.message)'))->await() === 'PHP rejected', 'Rejected PHP callback');
    $console = new Amp\DeferredFuture();
    $pages[0]->on('console', function ($message) use ($console) { return $message->text()->map(function ($text) use ($console) { if ($text === 'event works' && !$console->isComplete()) $console->complete($text); }); })->await();
    $pages[0]->evaluate('console.log("event works")')->await();
    check($console->getFuture()->await(new Amp\TimeoutCancellation(2)) === 'event works', 'Console event');
    $png = $pages[0]->screenshot()->await();
    check(str_starts_with($png, "\x89PNG\r\n\x1a\n"), 'Screenshot binary');
    $context->close()->await();
    $browser->disconnect()->await();
    echo json_encode(['status' => 'PASS', 'parallel_ms' => round($elapsed, 2), 'order' => $order], JSON_PRETTY_PRINT), "\n";
} finally { $client->close(); }
