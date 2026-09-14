<?php

declare(strict_types=1);

use Nesk\Puphpeteer\Puppeteer\Puppeteer;
use Nesk\Puphpeteer\RemoteObject;
use Nesk\Puphpeteer\Resources\Page;
use Nesk\Rialto\Data\JsFunction;

use function Amp\async;
use function Amp\Future\await;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cycles = filter_var(getopt('', ['cycles:'])['cycles'] ?? '50', FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
if (false === $cycles) {
    throw new InvalidArgumentException('--cycles must be an integer >= 2');
}
$started = hrtime(true);
$memory = [];
$browser = (new Puppeteer())->launch(['headless' => true, 'args' => ['--no-proxy-server']]);
$fixture = 'file://' . dirname(__DIR__, 3) . '/examples/pages/index.html';
$initialContexts = count($browser->browserContexts());
$client = (new ReflectionProperty(RemoteObject::class, 'client'))->getValue($browser);
$registryBaseline = null;
try {
    for ($cycle = 0; $cycle < $cycles; ++$cycle) {
        $context = $browser->createBrowserContext();
        try {
            $pages = await([async(fn () => $context->newPage()), async(fn () => $context->newPage())]);
            await(array_map(static function ($page) use ($fixture) {
                return async(static function () use ($page, $fixture): void { $page->goto($fixture); });
            }, $pages));
            $page = $pages[0];
            $page->bringToFront();
            verify($page instanceof Page, 'Legacy Page alias');
            $page->click('#button');
            verify('clicked' === $page->querySelectorEval('#result', JsFunction::createWithParameters(['element'])->body('return element.textContent;')), 'Form interaction');
            $listener = static function (): void {};
            $page->on('console', $listener);
            $page->off('console', $listener);
            verify(0 === $page->listenerCount('console'), 'Listener removed');
            Amp\delay(0.01);
            $weakListener = WeakReference::create($listener);
            unset($listener);
            gc_collect_cycles();
            verify(null === $weakListener->get(), 'Removed callback retained');
            for ($i = 0; $i < 10; ++$i) {
                $handle = $page->evaluateHandle(new JsFunction('() => ({answer: 42})'));
                verify($handle->jsonValue() === ['answer' => 42], 'Handle value');
                $handle->dispose();
                $handle->release();
                $weak = WeakReference::create($handle);
                unset($handle);
                gc_collect_cycles();
                verify(null === $weak->get(), 'Released PHP handle retained');
            }
            try {
                $page->evaluate(new JsFunction('() => { throw new Error("release failure probe"); }'));
                throw new LogicException('Expected evaluation failure');
            } catch (RuntimeException $error) {
                verify(str_contains($error->getMessage(), 'release failure probe'), 'Wrong failure');
            }
            verify('PuPHPeteer example' === $page->title(), 'Recovery after failure');
            if (0 === $cycle % 10) {
                $outputPath = sys_get_temp_dir() . '/puphpeteer-release-' . bin2hex(random_bytes(8)) . '.png';
                try {
                    $png = $page->screenshot(['path' => $outputPath]);
                    verify(str_starts_with($png, "\x89PNG") && file_get_contents($outputPath) === $png, 'Screenshot file bytes');
                } finally {
                    if (is_file($outputPath)) {
                        unlink($outputPath);
                    }
                }
                $jsLog = new JsFunction('() => console.log("release log")');
                $page->on('console', $jsLog);
                $page->evaluate('console.log("probe")');
                $page->off('console', $jsLog);
                unset($jsLog);
                verify(str_starts_with($page->pdf(), '%PDF-'), 'PDF bytes');
                $stream = $page->createPDFStream();
                $stream->read();
                $stream->close();
                unset($stream);
            }
        } finally {
            $context->close();
            foreach ($pages ?? [] as $page) {
                $page->release();
            }
            $context->release();
            unset($pages, $page, $context, $listener);
        }
        verify(count($browser->browserContexts()) === $initialContexts, 'Browser context leaked');
        gc_collect_cycles();
        Amp\delay(0.01);
        $counts = [];
        foreach (['objects', 'callbacks', 'pending', 'timers', 'streams', 'logs'] as $name) {
            $counts[$name] = count((new ReflectionProperty($client, $name))->getValue($client));
        }
        $filesystem = (new ReflectionProperty($client, 'filesystem'))->getValue($client);
        $counts['fileHandles'] = null === $filesystem ? 0 : count((new ReflectionProperty($filesystem, 'handles'))->getValue($filesystem));
        $counts['logBytes'] = (new ReflectionProperty($client, 'logBytes'))->getValue($client);
        if (null === $registryBaseline) {
            $registryBaseline = $counts;
        }
        verify($counts === $registryBaseline, 'Transport registry grew: ' . json_encode([$registryBaseline, $counts]));
        $memory[] = memory_get_usage();
        if (($cycle + 1) % 10 === 0) {
            echo 'Completed ', $cycle + 1, '/', $cycles, " cycles\n";
        }
    }
} finally {
    $browser->close();
}
echo json_encode(['status' => 'PASS', 'cycles' => $cycles, 'wall_ms' => (hrtime(true) - $started) / 1e6,
    'php_memory_bytes' => $memory, 'php_peak_allocated_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), "\n";
