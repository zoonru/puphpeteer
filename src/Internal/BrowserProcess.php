<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

use function Amp\async;

/** @internal Owns only the Chrome process and temporary profile created by launch(). */
final class BrowserProcess
{
    private ?Process $process = null;
    private ?string $temporaryProfile = null;
    public readonly string $endpoint;

    public function __construct(string $executable, array $options)
    {
        $arguments = self::defaultArgs($options);
        $profile = $options['userDataDir'] ?? null;
        if (null === $profile) {
            $profile = sys_get_temp_dir() . '/puphpeteer-' . bin2hex(random_bytes(8));
            if (!mkdir($profile, 0700)) {
                throw new RuntimeException('Cannot create Chrome profile');
            }
            $this->temporaryProfile = $profile;
        }
        $arguments[] = '--remote-debugging-port=0';
        $arguments[] = '--user-data-dir=' . $profile;
        try {
            $environment = getenv();
            $environment = is_array($environment) ? $environment : [];
            foreach ($options['env'] ?? [] as $name => $value) {
                $environment[(string) $name] = (string) $value;
            }
            $this->process = Process::start([$executable, ...$arguments], environment: $environment);
            $this->process->getStdin()->close();
            $stdout = $this->process->getStdout();
            $dump = $options['dumpio'] ?? false;
            async(static function () use ($stdout, $dump): void {
                while (($chunk = $stdout->read()) !== null) {
                    if ($dump) {
                        \Amp\ByteStream\getStdout()->write($chunk);
                    }
                }
            })->ignore();
            $timeout = $options['timeout'] ?? 30000;
            $cancellation = $timeout > 0 ? new TimeoutCancellation($timeout / 1000) : null;
            $stderr = $this->process->getStderr();
            $buffer = '';
            while (($chunk = $stderr->read($cancellation)) !== null) {
                if ($dump) {
                    \Amp\ByteStream\getStderr()->write($chunk);
                }
                $buffer .= $chunk;
                if (preg_match('~DevTools listening on (ws://[^\s]+)~', $buffer, $match)) {
                    $this->endpoint = $match[1];
                    async(static function () use ($stderr, $dump): void {
                        while (($chunk = $stderr->read()) !== null) {
                            if ($dump) {
                                \Amp\ByteStream\getStderr()->write($chunk);
                            }
                        }
                    })->ignore();

                    return;
                }
                $buffer = substr($buffer, -16384);
            }
            throw new RuntimeException('Chrome exited before exposing DevTools: ' . $buffer);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @return list<string> */
    public static function defaultArgs(array $options = []): array
    {
        $headless = $options['headless'] ?? true;
        if (!in_array($headless, [true, false, 'shell'], true)) {
            throw new InvalidArgumentException('Invalid headless value');
        }
        $key = (is_bool($headless) ? ($headless ? 'true' : 'false') : $headless) . ':' . (($options['devtools'] ?? false) ? 'true' : 'false');
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/launch-defaults.json');
        if (false === $source) {
            throw new RuntimeException('Missing generated Chrome defaults');
        }
        /** @var array<string,list<string>> $defaultsByMode */
        $defaultsByMode = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $defaults = $defaultsByMode[$key];
        $ignore = $options['ignoreDefaultArgs'] ?? false;
        if (true === $ignore) {
            $defaults = [];
        } elseif (is_array($ignore)) {
            $defaults = array_values(array_diff($defaults, $ignore));
        }
        $args = $options['args'] ?? [];
        if (!is_array($args) || !array_is_list($args)) {
            throw new InvalidArgumentException('args must be a list of strings');
        }
        $hasUrl = false;
        foreach ($args as $argument) {
            if (!is_string($argument)) {
                throw new InvalidArgumentException('args must be a list of strings');
            }
            if (!str_starts_with($argument, '-')) {
                $hasUrl = true;
            }
            $defaults[] = $argument;
        }
        if (!$hasUrl) {
            $defaults[] = 'about:blank';
        }

        return $defaults;
    }

    public function close(): void
    {
        try {
            if (null !== $this->process) {
                // Browser.close responds before Chrome finishes shutting down its children.
                // Let it finish before removing the profile; kill only an unresponsive process.
                try {
                    $this->process->join(new TimeoutCancellation(5));
                } catch (CancelledException) {
                    if ($this->process->isRunning()) {
                        $this->killProcessTree($this->process);
                    }
                    $this->process->join(new TimeoutCancellation(5));
                }
            }
        } finally {
            $this->process = null;
            if (null !== $this->temporaryProfile) {
                $profile = $this->temporaryProfile;
                $this->temporaryProfile = null;
                $this->removeProfile($profile);
            }
        }
    }

    private function killProcessTree(Process $process): void
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                return;
            }
            $ps = Process::start(['/bin/ps', '-axo', 'pid=,ppid=']);
            $rows = \Amp\ByteStream\buffer($ps->getStdout(), new TimeoutCancellation(5));
            $ps->join(new TimeoutCancellation(5));
            $parents = [];
            foreach (explode("\n", $rows) as $row) {
                if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $row, $match)) {
                    $parents[(int) $match[1]] = (int) $match[2];
                }
            }
            $selected = [$process->getPid() => true];
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
                if ($pid !== $process->getPid()) {
                    @posix_kill($pid, 9);
                }
            }
        } finally {
            $process->kill();
        }
    }

    private function removeProfile(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($path);
    }
}
