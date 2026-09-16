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
    private const string POSIX_LAUNCHER = <<<'PHP'
        $processGroupId = posix_setsid();
        if ($processGroupId === -1) {
            throw new RuntimeException('Cannot create process session');
        }
        fwrite(STDERR, "PuPHPeteer process group: $processGroupId\n");
        pcntl_exec($argv[1], array_slice($argv, 2));
        throw new RuntimeException('Cannot execute browser');
        PHP;
    private ?Process $process = null;
    private ?int $processGroupId = null;
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
            $command = [$executable, ...$arguments];
            $usesProcessGroup = false;
            if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec') && function_exists('posix_setsid') && function_exists('posix_kill')) {
                $command = [PHP_BINARY, '-r', self::POSIX_LAUNCHER, $executable, ...$arguments];
                $usesProcessGroup = true;
            }
            $this->process = Process::start($command, environment: $environment);
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
                if ($usesProcessGroup && preg_match('/PuPHPeteer process group: (\d+)/', $buffer, $processGroup)) {
                    $this->processGroupId = (int) $processGroup[1];
                }
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
        $profile = $this->temporaryProfile;
        $processGroupId = $this->processGroupId;
        try {
            if (null !== $this->process) {
                // Browser.close responds before Chrome finishes shutting down its children.
                // Let it finish before removing the profile; kill only an unresponsive process.
                try {
                    $this->process->join(new TimeoutCancellation(5));
                } catch (CancelledException) {
                    if ($this->process->isRunning()) {
                        $this->killProcess($this->process, $processGroupId);
                    }
                    $this->process->join(new TimeoutCancellation(5));
                }
            }
        } finally {
            if (null !== $processGroupId) {
                $this->killProcessGroup($processGroupId);
            }
            $this->process = null;
            $this->processGroupId = null;
            if (null !== $profile) {
                $this->removeProfile($profile);
                $this->temporaryProfile = null;
            }
        }
    }

    private function killProcess(Process $process, ?int $processGroupId): void
    {
        if (null === $processGroupId) {
            $process->kill();

            return;
        }
        $this->signalProcessGroup($processGroupId, 9);
    }

    private function killProcessGroup(int $processGroupId): void
    {
        if (!$this->signalProcessGroup($processGroupId, 9)) {
            return;
        }
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            usleep(20000);
            if (!$this->signalProcessGroup($processGroupId, 0)) {
                return;
            }
        }
        throw new RuntimeException('Cannot terminate Chrome process group');
    }

    private function signalProcessGroup(int $processGroupId, int $signal): bool
    {
        if (posix_kill(-$processGroupId, $signal)) {
            return true;
        }
        $error = posix_get_last_error();
        if (PCNTL_ESRCH === $error) {
            return false;
        }
        throw new RuntimeException(sprintf('Cannot signal Chrome process group: %s', posix_strerror($error)));
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
