<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Browser;

use Amp\Process\Process;
use Amp\Socket\ServerSocket;
use Amp\TimeoutCancellation;
use Nesk\Puphpeteer\Internal\BrowserProcess;
use function Amp\async;
use function Amp\Socket\listen;

/** Shared PHP orchestration for browser smoke tests and benchmarks. */
final class BrowserRunner
{
    private ServerSocket $fixture;
    private string $extension;
    public readonly string $url;

    public function __construct()
    {
        if (!getenv('QUICKJS_EXTENSION')) {
            throw new \RuntimeException('Set QUICKJS_EXTENSION. See docs/quickjs.md.');
        }
        $this->extension = (string) getenv('QUICKJS_EXTENSION');
        $this->fixture = listen('127.0.0.1:0');
        $this->url = 'http://' . (string) $this->fixture->getAddress() . '/';
        $fixture = $this->fixture;
        async(static function () use ($fixture): void {
            while (($socket = $fixture->accept()) !== null) {
                async(static function () use ($socket): void {
                    try {
                        $request = '';
                        $timeout = new TimeoutCancellation(10);
                        while (!str_contains($request, "\r\n\r\n")) {
                            $chunk = $socket->read($timeout);
                            if ($chunk === null) { return; }
                            $request .= $chunk;
                            if (strlen($request) > 16384) { return; }
                        }
                        $body = '<!doctype html><title>QuickJS fixture</title><button id="button" onclick="document.querySelector(\'#result\').textContent=\'clicked\'">Go</button><div id="result"></div>';
                        $socket->write("HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
                    } catch (\Amp\CancelledException | \Amp\ByteStream\StreamException) {
                        // A browser may abandon a request while closing its context.
                    } finally { $socket->close(); }
                })->ignore();
            }
        })->ignore();
    }

    public function launch(): BrowserProcess
    {
        return new BrowserProcess((new \Nesk\Puphpeteer\Puppeteer())->executablePath(), ['headless' => true, 'args' => ['--no-proxy-server']]);
    }

    /**
     * @psalm-mutation-free
     * @return list<string>
     */
    public function command(string $script): array
    {
        return [getenv('PHP_BIN') ?: PHP_BINARY, '-n', '-d', 'extension=' . $this->extension, $script];
    }

    /** @return array<string,string> */
    public function environment(BrowserProcess $browser): array
    {
        return [...getenv(), 'BROWSER_WS' => $browser->endpoint, 'FIXTURE_URL' => $this->url, 'EXAMPLE_URL' => $this->url];
    }

    /**
     * Drain both pipes concurrently so a full stderr pipe cannot block the child.
     * @param null|\Closure(string):void $onStdout
     * @return array{code:int,stdout:string,stderr:string}
     */
    public static function collect(Process $child, float $timeout, ?\Closure $onStdout = null, bool $stream = false): array
    {
        $child->getStdin()->close();
        $stdout = $stderr = '';
        $readOut = async(static function () use ($child, &$stdout, $onStdout, $stream): void {
            while (($chunk = $child->getStdout()->read()) !== null) {
                $stdout .= $chunk;
                if ($stream) { fwrite(STDOUT, $chunk); }
                $onStdout?->__invoke($chunk);
            }
        });
        $readErr = async(static function () use ($child, &$stderr, $stream): void {
            while (($chunk = $child->getStderr()->read()) !== null) {
                $stderr .= $chunk;
                if ($stream) { fwrite(STDERR, $chunk); }
            }
        });
        try {
            $code = $child->join(new TimeoutCancellation($timeout));
        } finally {
            self::terminate($child);
            $child->join();
            $readOut->await();
            $readErr->await();
        }
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function terminate(Process $child): void
    {
        if (!$child->isRunning()) { return; }
        try {
            // Killing /usr/bin/time alone leaves its measured PHP child alive.
            if (PHP_OS_FAMILY !== 'Windows') {
                $ps = Process::start(['/bin/ps', '-axo', 'pid=,ppid=']);
                $rows = \Amp\ByteStream\buffer($ps->getStdout(), new TimeoutCancellation(5));
                $ps->join(new TimeoutCancellation(5));
                $parents = [];
                foreach (explode("\n", trim($rows)) as $line) {
                    if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $match)) {
                        $parents[(int) $match[1]] = (int) $match[2];
                    }
                }
                $selected = [$child->getPid() => true];
                do {
                    $changed = false;
                    foreach ($parents as $pid => $parent) {
                        if (isset($selected[$parent]) && !isset($selected[$pid])) {
                            $selected[$pid] = true;
                            $changed = true;
                        }
                    }
                } while ($changed);
                foreach (array_reverse(array_keys($selected)) as $pid) {
                    if ($pid !== $child->getPid()) { posix_kill($pid, 9); }
                }
            }
        } finally { $child->kill(); }
    }

    public function close(): void { $this->fixture->close(); }

    public static function removeDirectory(string $path): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($path);
    }
}
