<?php

declare(strict_types=1);
namespace Nesk\Puphpeteer\Internal;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
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
        if ($profile === null) {
            $profile = sys_get_temp_dir() . '/puphpeteer-' . bin2hex(random_bytes(8));
            if (!mkdir($profile, 0700)) { throw new \RuntimeException('Cannot create Chrome profile'); }
            $this->temporaryProfile = $profile;
        }
        $arguments[] = '--remote-debugging-port=0';
        $arguments[] = '--user-data-dir=' . $profile;
        try {
            $this->process = Process::start([$executable, ...$arguments], environment: $options['env'] ?? []);
            $this->process->getStdin()->close();
            $stdout = $this->process->getStdout();
            $dump = $options['dumpio'] ?? false;
            async(static function () use ($stdout, $dump): void {
                while (($chunk = $stdout->read()) !== null) { if ($dump) { fwrite(STDOUT, $chunk); } }
            })->ignore();
            $timeout = $options['timeout'] ?? 30000;
            $cancellation = $timeout > 0 ? new TimeoutCancellation($timeout / 1000) : null;
            $stderr = $this->process->getStderr();
            $buffer = '';
            while (($chunk = $stderr->read($cancellation)) !== null) {
                if ($dump) { fwrite(STDERR, $chunk); }
                $buffer .= $chunk;
                if (preg_match('~DevTools listening on (ws://[^\s]+)~', $buffer, $match)) {
                    $this->endpoint = $match[1];
                    async(static function () use ($stderr, $dump): void {
                        while (($chunk = $stderr->read()) !== null) { if ($dump) { fwrite(STDERR, $chunk); } }
                    })->ignore();
                    return;
                }
                $buffer = substr($buffer, -16384);
            }
            throw new \RuntimeException('Chrome exited before exposing DevTools: ' . $buffer);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @return list<string> */
    public static function defaultArgs(array $options = []): array
    {
        $headless = $options['headless'] ?? true;
        if (!in_array($headless, [true, false, 'shell'], true)) { throw new \InvalidArgumentException('Invalid headless value'); }
        $key = (is_bool($headless) ? ($headless ? 'true' : 'false') : $headless) . ':' . (($options['devtools'] ?? false) ? 'true' : 'false');
        $source = file_get_contents(dirname(__DIR__, 2) . '/resources/launch-defaults.json');
        if ($source === false) { throw new \RuntimeException('Missing generated Chrome defaults'); }
        /** @var array<string,list<string>> $defaultsByMode */
        $defaultsByMode = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $defaults = $defaultsByMode[$key];
        $ignore = $options['ignoreDefaultArgs'] ?? false;
        if ($ignore === true) { $defaults = []; }
        elseif (is_array($ignore)) { $defaults = array_values(array_diff($defaults, $ignore)); }
        $args = $options['args'] ?? [];
        if (!is_array($args) || !array_is_list($args)) { throw new \InvalidArgumentException('args must be a list of strings'); }
        $hasUrl = false;
        foreach ($args as $argument) {
            if (!is_string($argument)) { throw new \InvalidArgumentException('args must be a list of strings'); }
            if (!str_starts_with($argument, '-')) { $hasUrl = true; }
            $defaults[] = $argument;
        }
        if (!$hasUrl) { $defaults[] = 'about:blank'; }
        return $defaults;
    }

    public function close(): void
    {
        if ($this->process !== null) {
            $this->process->kill();
            $this->process->join(new TimeoutCancellation(5));
            $this->process = null;
        }
        if ($this->temporaryProfile !== null) {
            $this->removeProfile($this->temporaryProfile);
            $this->temporaryProfile = null;
        }
    }

    private function removeProfile(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
            else { unlink($file->getPathname()); }
        }
        rmdir($path);
    }
}
