<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;
use function Amp\delay;
use function Amp\File\createDirectory;
use function Amp\File\deleteDirectory;
use function Amp\File\deleteFile;
use function Amp\File\isDirectory;
use function Amp\File\isSymlink;
use function Amp\File\listFiles;
use function Amp\File\read;

/** @internal Owns only the Chrome process and temporary profile created by launch(). */
final class BrowserProcess
{
    private const string POSIX_LAUNCHER = <<<'PHP'
        if (posix_setsid() === -1) {
            throw new RuntimeException('Cannot create process session');
        }
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
            createDirectory($profile, 0700);
            $this->temporaryProfile = $profile;
        }
        $arguments[] = '--remote-debugging-port=0';
        $arguments[] = '--user-data-dir=' . $profile;
        try {
            $environment = array_map(strval(...), array_replace(getenv() ?: [], $options['env'] ?? []));
            $command = [$executable, ...$arguments];
            $usesProcessGroup = PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_exec') && function_exists('posix_setsid') && function_exists('posix_kill');
            if ($usesProcessGroup) {
                $command = [PHP_BINARY, '-r', self::POSIX_LAUNCHER, $executable, ...$arguments];
            }
            $this->process = Process::start($command, environment: $environment);
            $this->processGroupId = $usesProcessGroup ? $this->process->getPid() : null;
            $this->process->getStdin()->close();
            $dump = $options['dumpio'] ?? false;
            self::consume($this->process->getStdout(), $dump ? getStdout() : null);
            $timeout = $options['timeout'] ?? 30000;
            $cancellation = $timeout > 0 ? new TimeoutCancellation($timeout / 1000) : null;
            $stderr = $this->process->getStderr();
            $buffer = '';
            while (($chunk = $stderr->read($cancellation)) !== null) {
                if ($dump) {
                    getStderr()->write($chunk);
                }
                $buffer .= $chunk;
                if (preg_match('~DevTools listening on (ws://[^\s]+)~', $buffer, $match)) {
                    $this->endpoint = $match[1];
                    self::consume($stderr, $dump ? getStderr() : null);

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
        $source = read(dirname(__DIR__, 2) . '/resources/launch-defaults.json');
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
                        if (null === $processGroupId || !$this->signalProcessGroup($processGroupId, 9)) {
                            $this->process->kill();
                        }
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

    private function killProcessGroup(int $processGroupId): void
    {
        if (!$this->signalProcessGroup($processGroupId, 9)) {
            return;
        }
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            delay(0.02);
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
        if (!isDirectory($path)) {
            return;
        }
        foreach (listFiles($path) as $name) {
            $entry = $path . '/' . $name;
            if (isDirectory($entry) && !isSymlink($entry)) {
                $this->removeProfile($entry);
            } else {
                deleteFile($entry);
            }
        }
        deleteDirectory($path);
    }

    private static function consume(ReadableStream $source, ?WritableStream $destination): void
    {
        async(static function () use ($source, $destination): void {
            if (null !== $destination) {
                pipe($source, $destination);

                return;
            }
            while (null !== $source->read()) {
            }
        })->ignore();
    }
}
