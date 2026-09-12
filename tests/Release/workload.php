<?php

declare(strict_types=1);

use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;
use function Amp\async;
use function Amp\Future\await;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$cycles = filter_var(getenv('RELEASE_CYCLES') === false ? '50' : getenv('RELEASE_CYCLES'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
if ($cycles === false) { throw new InvalidArgumentException('RELEASE_CYCLES must be an integer >= 2'); }
$started = hrtime(true);
$memory = [];
$browser = (new Puppeteer())->launch(['headless' => true, 'args' => ['--no-proxy-server']]);
$fixture = 'file://' . dirname(__DIR__, 2) . '/examples/pages/index.html';
$initialContexts = count($browser->browserContexts());
$client = (new ReflectionProperty(Nesk\Puphpeteer\RemoteObject::class, 'client'))->getValue($browser);
$registryBaseline = null;
try {
    for ($cycle = 0; $cycle < $cycles; $cycle++) {
        $context = $browser->createBrowserContext();
        try {
            $pages = await([async(fn() => $context->newPage()), async(fn() => $context->newPage())]);
            await(array_map(static function ($page) use ($fixture) {
                return async(static function () use ($page, $fixture): void { $page->goto($fixture); });
            }, $pages));
            $page = $pages[0];
            $page->bringToFront();
            verify($page instanceof Nesk\Puphpeteer\Resources\Page, 'Legacy Page alias');
            $page->click('#button');
            verify($page->querySelectorEval('#result', JsFunction::createWithParameters(['element'])->body('return element.textContent;')) === 'clicked', 'Form interaction');
            $listener = static function (): void {};
            $page->on('console', $listener);
            $page->off('console', $listener);
            verify($page->listenerCount('console') === 0, 'Listener removed');
            Amp\delay(0.01);
            $weakListener = WeakReference::create($listener);
            unset($listener);
            gc_collect_cycles();
            verify($weakListener->get() === null, 'Removed callback retained');
            for ($i = 0; $i < 10; $i++) {
                $handle = $page->evaluateHandle(new JsFunction('() => ({answer: 42})'));
                verify($handle->jsonValue() === ['answer' => 42], 'Handle value');
                $handle->dispose();
                $handle->release();
                $weak = WeakReference::create($handle);
                unset($handle);
                gc_collect_cycles();
                verify($weak->get() === null, 'Released PHP handle retained');
            }
            try {
                $page->evaluate(new JsFunction('() => { throw new Error("release failure probe"); }'));
                throw new LogicException('Expected evaluation failure');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'release failure probe'), 'Wrong failure');
            }
            verify($page->title() === 'PuPHPeteer example', 'Recovery after failure');
            if ($cycle % 10 === 0) {
                verify(str_starts_with($page->screenshot(), "\x89PNG"), 'Screenshot bytes');
                verify(str_starts_with($page->pdf(), '%PDF-'), 'PDF bytes');
            }
        } finally {
            $context->close();
            foreach ($pages ?? [] as $page) { $page->release(); }
            $context->release();
            unset($pages, $page, $context, $listener);
        }
        verify(count($browser->browserContexts()) === $initialContexts, 'Browser context leaked');
        gc_collect_cycles();
        Amp\delay(0.01);
        $counts = [];
        foreach (['objects', 'callbacks', 'pending', 'timers'] as $name) {
            $counts[$name] = count((new ReflectionProperty($client, $name))->getValue($client));
        }
        if ($registryBaseline === null) { $registryBaseline = $counts; }
        verify($counts === $registryBaseline, 'Transport registry grew: ' . json_encode([$registryBaseline, $counts]));
        $memory[] = memory_get_usage();
        if (($cycle + 1) % 10 === 0) { echo 'Completed ', $cycle + 1, '/', $cycles, " cycles\n"; }
    }
} finally { $browser->close(); }
echo json_encode(['status' => 'PASS', 'cycles' => $cycles, 'wall_ms' => (hrtime(true) - $started) / 1e6,
    'php_memory_bytes' => $memory, 'php_peak_allocated_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
