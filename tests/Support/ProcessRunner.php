<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Tests\Support;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use function Amp\async;

/** Utilities for running isolated PHP processes in test and benchmark scripts. */
final class ProcessRunner
{
    /**
     * Drain both pipes concurrently so a full stderr pipe cannot block the child.
     *
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
