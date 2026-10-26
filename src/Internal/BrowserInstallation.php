<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use FilesystemIterator;
use RuntimeException;
use SplFileInfo;

/** @internal Shared Chrome installation layout; also used during Docker builds. */
final class BrowserInstallation
{
    public readonly string $version;
    public readonly string $platform;
    public readonly string $cache;

    public function __construct(string $packageRoot)
    {
        $lock = json_decode(file_get_contents($packageRoot . '/upstream/lock.json') ?: '', true, flags: JSON_THROW_ON_ERROR);
        $version = $lock['package']['chromeBuildId'] ?? null;
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+\.\d+$/D', $version)) {
            throw new RuntimeException('Invalid Chrome version in upstream/lock.json');
        }
        $this->version = $version;
        $this->platform = self::platform(PHP_OS_FAMILY, php_uname('m'));
        $cache = getenv('PUPPETEER_CACHE_DIR');
        if (false === $cache || '' === $cache) {
            $cache = '.chrome';
        }
        $absolute = str_starts_with($cache, '/') || str_starts_with($cache, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $cache);
        $this->cache = $absolute ? $cache : self::projectRoot($packageRoot) . '/' . $cache;
    }

    public static function projectRoot(string $packageRoot): string
    {
        $directory = dirname($packageRoot);
        while ($directory !== dirname($directory)) {
            if (is_file($directory . '/composer.json')) {
                return $directory;
            }
            $directory = dirname($directory);
        }

        return $packageRoot;
    }

    /** @psalm-pure */
    public static function platform(string $os, string $architecture): string
    {
        return match ([$os, strtolower($architecture)]) {
            ['Linux', 'x86_64'], ['Linux', 'amd64'] => 'linux64',
            ['Linux', 'aarch64'], ['Linux', 'arm64'] => 'linux-arm64',
            ['Darwin', 'x86_64'] => 'mac-x64',
            ['Darwin', 'arm64'], ['Darwin', 'aarch64'] => 'mac-arm64',
            ['Windows', 'amd64'], ['Windows', 'x86_64'] => 'win64',
            ['Windows', 'x86'], ['Windows', 'i386'], ['Windows', 'i686'] => 'win32',
            default => throw new RuntimeException("Unsupported Chrome platform: $os/$architecture"),
        };
    }

    /** @psalm-mutation-free */
    public function directory(): string
    {
        return $this->cache . '/' . $this->platform . '-' . $this->version;
    }

    /** @psalm-mutation-free */
    public function executable(?string $directory = null): string
    {
        $name = str_starts_with($this->platform, 'mac-')
            ? 'Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing'
            : (str_starts_with($this->platform, 'win') ? 'chrome.exe' : 'chrome');

        return ($directory ?? $this->directory()) . '/chrome-' . $this->platform . '/' . $name;
    }

    /** @psalm-mutation-free */
    public function url(): string
    {
        return "https://storage.googleapis.com/chrome-for-testing-public/{$this->version}/{$this->platform}/chrome-{$this->platform}.zip";
    }

    /** @psalm-pure */
    public static function skipDownload(): bool
    {
        $executable = getenv('PUPPETEER_EXECUTABLE_PATH');
        if (false !== $executable && '' !== $executable) {
            return true;
        }
        foreach (['PUPPETEER_SKIP_DOWNLOAD', 'PUPPETEER_CHROME_SKIP_DOWNLOAD', 'PUPPETEER_SKIP_CHROME_DOWNLOAD'] as $key) {
            if (in_array(strtolower(getenv($key) ?: ''), ['1', 'true', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Returns null when downloads are explicitly disabled. */
    public function install(): ?string
    {
        if (self::skipDownload()) {
            return null;
        }
        if (!is_dir($this->cache) && !mkdir($this->cache, 0777, true) && !is_dir($this->cache)) {
            throw new RuntimeException('Cannot create Chrome cache: ' . $this->cache);
        }
        $lock = fopen($this->cache . '/.install.lock', 'c');
        if (false === $lock) {
            throw new RuntimeException('Cannot open Chrome installation lock');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock Chrome cache');
            }
            if (is_file($this->executable()) && is_executable($this->executable())) {
                return $this->executable();
            }
            if (file_exists($this->directory())) {
                throw new RuntimeException('Incomplete Chrome installation; remove ' . $this->directory() . ' and retry');
            }
            $temporary = $this->cache . '/.download-' . bin2hex(random_bytes(8));
            if (!mkdir($temporary)) {
                throw new RuntimeException('Cannot create Chrome download directory');
            }
            $archive = $temporary . '/chrome.zip';
            $source = @fopen($this->url(), 'rb', false, stream_context_create(['http' => ['timeout' => 120]]));
            if (false === $source) {
                throw new RuntimeException('Cannot download Chrome: ' . $this->url());
            }
            try {
                $target = fopen($archive, 'wb');
                if (false === $target) {
                    throw new RuntimeException('Cannot write Chrome archive');
                }
                try {
                    if (false === stream_copy_to_stream($source, $target)) {
                        throw new RuntimeException('Chrome download failed');
                    }
                } finally {
                    fclose($target);
                }
            } finally {
                fclose($source);
            }
            $this->extract($archive, $temporary . '/unpacked');
            if (!rename($temporary . '/unpacked', $this->directory())) {
                throw new RuntimeException('Cannot publish Chrome installation');
            }

            return $this->executable();
        } finally {
            if (null !== $temporary && is_dir($temporary)) {
                self::removeDirectory($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Preserve executable permissions and macOS framework symlinks with unzip. */
    public function extract(string $archive, string $destination): void
    {
        $log = $archive . '.log';
        $process = proc_open(['unzip', '-q', $archive, '-d', $destination], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Install unzip to extract Chrome');
        }
        fclose($pipes[0]);
        $code = proc_close($process);
        if (0 !== $code) {
            throw new RuntimeException('Chrome extraction failed (requires unzip): ' . (file_get_contents($log) ?: ''));
        }
        if (!is_file($this->executable($destination)) || !is_executable($this->executable($destination))) {
            throw new RuntimeException('Chrome archive does not contain the expected executable');
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            unlink($path);

            return;
        }
        foreach (new FilesystemIterator($path) as $file) {
            if ($file instanceof SplFileInfo) {
                self::removeDirectory($file->getPathname());
            }
        }
        rmdir($path);
    }
}
