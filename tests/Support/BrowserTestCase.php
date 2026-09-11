<?php

declare(strict_types=1);

require_once __DIR__ . '/FixtureServer.php';

use Nesk\Puphpeteer\Browser;
use Nesk\Puphpeteer\BrowserContext;
use Nesk\Puphpeteer\Page;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Puphpeteer\Internal\Session;
use PHPUnit\Framework\TestCase;
use function Amp\delay;

/** Real-browser setup; excluded public APIs are replaced only in test setup/assertion helpers. */
abstract class BrowserTestCase extends TestCase
{
    protected Browser $browser;
    protected BrowserContext $context;
    protected Page $page;
    protected FixtureServer $server;
    protected static string $endpoint;
    private static mixed $process = null;
    private static ?string $profile = null;
    private static bool $shutdownRegistered = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function(static fn () => self::tearDownAfterClass());
            self::$shutdownRegistered = true;
        }
        if ($endpoint = getenv('PUPHPETEER_TEST_ENDPOINT')) {
            self::$endpoint = $endpoint;
            return;
        }
        $binary = getenv('PUPHPETEER_CHROME');
        if (!$binary) {
            $revisions = dirname(__DIR__, 2) . '/node_modules/puppeteer-core/lib/cjs/puppeteer/revisions.js';
            $source = is_file($revisions) ? file_get_contents($revisions) : '';
            if (preg_match("/chrome: '([^']+)'/", $source, $match)) {
                $cache = getenv('PUPPETEER_CACHE_DIR') ?: getenv('HOME') . '/.cache/puppeteer';
                $version = $match[1];
                $patterns = [
                    $cache . '/chrome/mac_arm-' . $version . '/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
                    $cache . '/chrome/mac-' . $version . '/chrome-mac-x64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing',
                    $cache . '/chrome/linux-' . $version . '/chrome-linux64/chrome',
                ];
                foreach ($patterns as $candidate) {
                    if (is_executable($candidate)) {
                        $binary = $candidate;
                        break;
                    }
                }
            }
        }
        if (!$binary) {
            throw new RuntimeException('Install the Chrome for Testing version pinned by Puppeteer, or set PUPHPETEER_CHROME / PUPHPETEER_TEST_ENDPOINT.');
        }
        self::$profile = sys_get_temp_dir() . '/puphpeteer-test-' . bin2hex(random_bytes(8));
        mkdir(self::$profile, 0700);
        self::$process = proc_open([$binary, '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-dev-shm-usage', '--remote-debugging-port=0', '--remote-allow-origins=*', '--user-data-dir=' . self::$profile, 'about:blank'], [0 => ['pipe', 'r'], 1 => ['file', self::$profile . '/stdout.log', 'a'], 2 => ['file', self::$profile . '/stderr.log', 'a']], $pipes);
        fclose($pipes[0]);
        $deadline = microtime(true) + 15;
        $file = self::$profile . '/DevToolsActivePort';
        $parts = [];
        do {
            if (is_file($file)) {
                $parts = explode("\n", trim(file_get_contents($file)));
                if (count($parts) === 2 && ctype_digit($parts[0]) && str_starts_with($parts[1], '/devtools/browser/')) {
                    break;
                }
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        if (count($parts) !== 2 || !str_starts_with($parts[1], '/devtools/browser/')) {
            $log = file_get_contents(self::$profile . '/stderr.log');
            self::tearDownAfterClass();
            throw new RuntimeException('Chrome did not expose its debug endpoint: ' . $log);
        }
        [$port, $path] = $parts;
        self::$endpoint = 'ws://127.0.0.1:' . $port . $path;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$profile !== null) {
            self::removeDirectory(self::$profile);
            self::$profile = null;
        }
    }

    protected function setUp(): void
    {
        $this->server = new FixtureServer();
        $this->browser = (new Puppeteer())->connect(['browserWSEndpoint' => self::$endpoint])->await();
        $this->context = $this->browser->createBrowserContext()->await();
        $this->page = $this->context->newPage()->await();
    }

    protected function tearDown(): void
    {
        try {
            // A disconnected browser deliberately has no live connection for disposal.
            if (isset($this->context) && !self::property($this->browser, 'connection')->isClosed()) {
                $this->context->close()->await();
            }
        } finally {
            if (isset($this->browser)) {
                $this->browser->disconnect()->await();
            }
            if (isset($this->server)) {
                $this->server->close();
            }
        }
    }

    protected function url(string $path): string { return $this->server->url($path); }
    protected function httpsUrl(string $path): string { return $this->server->url($path, true); }
    protected function setRoute(string $path, callable $handler): void { $this->server->route($path, $handler); }
    protected function requestHeaders(string $path): array { return $this->waitForRequest($path)['headers']; }

    protected function waitForRequest(string $path): array
    {
        $deadline = microtime(true) + 5;
        while (!($requests = $this->server->requests($path))) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('No fixture request: ' . $path);
            }
            delay(0.005);
        }
        return $requests[array_key_last($requests)];
    }

    protected function session(?Page $page = null): Session
    {
        return self::property($page ?? $this->page, 'session');
    }

    protected function cdpSession(): Session { return $this->session(); }

    protected function cdp(string $method, array $params = []): array
    {
        return $this->session()->send($method, $params)->await();
    }

    protected function onCdp(string $event, callable $callback): int { return $this->session()->observe($event, $callback); }
    protected function offCdp(string $event, int $id): void { $this->session()->off($event, $id); }

    protected static function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    protected static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
