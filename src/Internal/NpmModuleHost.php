<?php

declare(strict_types=1);

namespace Nesk\Puphpeteer\Internal;

use InvalidArgumentException;
use RuntimeException;

/** @internal Read-only CommonJS module host rooted in one application directory. */
final class NpmModuleHost
{
    private const int MAX_BYTES = 256 * 1024 * 1024;
    private const string BINARY_MARKER = "\xff";

    private readonly string $root;

    public function __construct(string $root)
    {
        $canonical = realpath($root);
        if (false === $canonical || !is_dir($canonical)) {
            throw new InvalidArgumentException("Module root is not a directory: $root");
        }

        $this->root = rtrim($canonical, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function resolve(string $specifier, ?string $parent = null): string
    {
        if ('' === $specifier || str_contains($specifier, "\0") || str_starts_with($specifier, '\\')) {
            throw new InvalidArgumentException("Invalid module specifier: $specifier");
        }

        if (str_starts_with($specifier, '/')) {
            if (null !== $parent) {
                throw new InvalidArgumentException("Absolute require is not allowed: $specifier");
            }

            return $this->resolvePath($specifier) ?? throw new RuntimeException("Cannot resolve module: $specifier");
        }

        $directory = null === $parent ? $this->root : dirname($this->file($parent));
        if ('.' === $specifier || '..' === $specifier || str_starts_with($specifier, './') || str_starts_with($specifier, '../')) {
            $resolved = $this->resolvePath($directory . '/' . $specifier);
        } else {
            if (str_starts_with($specifier, '#') || str_starts_with($specifier, 'node:')) {
                throw new RuntimeException("Unsupported module specifier: $specifier");
            }
            $resolved = $this->resolvePackage($specifier, $directory);
        }

        return $resolved ?? throw new RuntimeException("Cannot resolve module: $specifier");
    }

    public function readSource(string $path): string
    {
        $source = file_get_contents($this->file($path), false, null, 0, self::MAX_BYTES + 1);
        if (false !== $source && strlen($source) > self::MAX_BYTES) {
            throw new RuntimeException("Module exceeds byte limit: $path");
        }

        return false === $source ? throw new RuntimeException("Cannot read module: $path") : $source;
    }

    public function readBinary(string $path): string
    {
        return self::BINARY_MARKER . $this->readSource($path);
    }

    /** @psalm-pure */
    public function decodeUtf8(string $bytes): string
    {
        if (1 !== preg_match('//u', $bytes)) {
            throw new InvalidArgumentException('Invalid UTF-8 data');
        }

        return $bytes;
    }

    /** Node's zlib.crc32 accepts a previous checksum as the optional seed.
     * @psalm-external-mutation-free
     */
    public function crc32(string $bytes, int $value = 0): int
    {
        if ($value < 0 || $value > 0xFFFFFFFF) {
            throw new InvalidArgumentException('Invalid CRC32 seed');
        }
        if (0 === $value) {
            return crc32($bytes) & 0xFFFFFFFF;
        }

        static $table = null;
        if (null === $table) {
            $table = [];
            for ($n = 0; $n < 256; ++$n) {
                $crc = $n;
                for ($bit = 0; $bit < 8; ++$bit) {
                    $crc = ($crc >> 1) ^ (($crc & 1) ? 0xEDB88320 : 0);
                }
                $table[] = $crc;
            }
        }

        $crc = ~$value;
        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $crc = (($crc >> 8) & 0x00FFFFFF) ^ $table[($crc ^ ord($bytes[$i])) & 0xFF];
        }

        return ~$crc & 0xFFFFFFFF;
    }

    public function exists(string $path): bool
    {
        $canonical = realpath($path);
        if (false === $canonical) {
            return false;
        }
        $this->assertWithinRoot($canonical);

        return is_file($canonical);
    }

    /** @psalm-pure */
    public function inflateRaw(string $bytes, ?int $limit = null): string
    {
        $limit ??= self::MAX_BYTES;
        if ($limit < 1 || $limit > self::MAX_BYTES) {
            throw new InvalidArgumentException('Invalid inflate output limit');
        }
        $output = @gzinflate($bytes, $limit);
        if (false === $output) {
            throw new RuntimeException('Invalid raw DEFLATE data or output exceeds limit');
        }

        return self::BINARY_MARKER . $output;
    }

    /** @psalm-pure */
    public function deflateRaw(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Deflate input exceeds byte limit');
        }
        $output = gzdeflate($bytes);
        if (false === $output) {
            throw new RuntimeException('Cannot compress data');
        }

        return self::BINARY_MARKER . $output;
    }

    private function resolvePackage(string $specifier, string $directory): ?string
    {
        $parts = explode('/', $specifier);
        if (str_starts_with($specifier, '@')) {
            if (count($parts) < 2 || '' === $parts[1]) {
                throw new InvalidArgumentException("Invalid package specifier: $specifier");
            }
            $name = $parts[0] . '/' . $parts[1];
            $subpath = implode('/', array_slice($parts, 2));
        } else {
            $name = $parts[0];
            $subpath = implode('/', array_slice($parts, 1));
        }
        if ('' === $name || '.' === $name || '..' === $name || str_contains($specifier, '\\') || preg_match('~(^|/)\.{1,2}(/|$)~', $specifier)) {
            throw new InvalidArgumentException("Invalid package specifier: $specifier");
        }

        for ($search = $directory;; $search = dirname($search)) {
            $package = $search . '/node_modules/' . $name;
            if (file_exists($package)) {
                $canonical = realpath($package);
                if (false === $canonical) {
                    throw new RuntimeException("Cannot access package: $name");
                }
                $this->assertWithinRoot($canonical);
                if (!is_dir($canonical)) {
                    throw new RuntimeException("Package is not a directory: $name");
                }

                return $this->resolvePackageEntry($canonical, $subpath);
            }
            if ($search === $this->root) {
                break;
            }
        }

        return null;
    }

    private function resolvePackageEntry(string $package, string $subpath): ?string
    {
        $manifestPath = $package . '/package.json';
        $manifest = is_file($manifestPath) ? $this->readManifest($manifestPath) : [];
        if (array_key_exists('exports', $manifest)) {
            $key = '' === $subpath ? '.' : './' . $subpath;
            $target = $this->exportTarget($manifest['exports'], $key);
            if (null === $target) {
                throw new RuntimeException("Package export is unavailable: $package ($key)");
            }
            if (!str_starts_with($target, './')) {
                throw new RuntimeException("Unsupported package export target: $target");
            }

            return $this->withinPackage($this->resolvePath($package . '/' . $target), $package);
        }
        if ('' !== $subpath) {
            return $this->withinPackage($this->resolvePath($package . '/' . $subpath), $package);
        }
        if (isset($manifest['main']) && is_string($manifest['main']) && '' !== $manifest['main'] && '.' !== $manifest['main'] && './' !== $manifest['main']) {
            $main = $this->withinPackage($this->resolvePath($package . '/' . $manifest['main']), $package);
            if (null !== $main) {
                return $main;
            }
        }

        return $this->resolvePath($package . '/index');
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        $decoded = json_decode($this->readSource($path), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("Invalid package.json: $path");
        }

        return $decoded;
    }

    private function exportTarget(mixed $exports, string $key): ?string
    {
        if (is_string($exports)) {
            return '.' === $key ? $exports : null;
        }
        if (!is_array($exports)) {
            return null;
        }
        if (array_key_exists($key, $exports)) {
            return $this->conditionalTarget($exports[$key]);
        }
        if ('.' !== $key || !array_filter(array_keys($exports), static fn (string|int $name): bool => str_starts_with((string) $name, '.'))) {
            return $this->conditionalTarget($exports);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private function conditionalTarget(mixed $target): ?string
    {
        if (is_string($target)) {
            return $target;
        }
        if (!is_array($target)) {
            return null;
        }
        foreach ($target as $condition => $value) {
            if (is_int($condition) || in_array($condition, ['require', 'node', 'default'], true)) {
                $selected = $this->conditionalTarget($value);
                if (null !== $selected) {
                    return $selected;
                }
            }
        }

        return null;
    }

    /** @param list<string> $visited */
    private function resolvePath(string $path, array $visited = []): ?string
    {
        foreach ([$path, $path . '.js', $path . '.cjs', $path . '.json'] as $candidate) {
            $canonical = realpath($candidate);
            if (false === $canonical) {
                continue;
            }
            $this->assertWithinRoot($canonical);
            if (is_file($canonical)) {
                return $canonical;
            }
        }

        $directory = realpath($path);
        if (false === $directory || !is_dir($directory)) {
            return null;
        }
        $this->assertWithinRoot($directory);
        if (in_array($directory, $visited, true)) {
            return null;
        }
        $visited[] = $directory;
        $manifestPath = $directory . '/package.json';
        if (is_file($manifestPath)) {
            $manifest = $this->readManifest($manifestPath);
            if (isset($manifest['main']) && is_string($manifest['main']) && '' !== $manifest['main'] && '.' !== $manifest['main'] && './' !== $manifest['main']) {
                $main = $this->resolvePath($directory . '/' . $manifest['main'], $visited);
                if (null !== $main) {
                    return $main;
                }
            }
        }

        return $this->resolvePath($directory . '/index', $visited);
    }

    /** @psalm-pure */
    private function withinPackage(?string $path, string $package): ?string
    {
        if (null !== $path && !str_starts_with($path, $package . '/')) {
            throw new RuntimeException("Module escapes package directory: $path");
        }

        return $path;
    }

    private function file(string $path): string
    {
        $canonical = realpath($path);
        if (false === $canonical || !is_file($canonical)) {
            throw new RuntimeException("Module file does not exist: $path");
        }
        $this->assertWithinRoot($canonical);

        return $canonical;
    }

    /** @psalm-mutation-free */
    private function assertWithinRoot(string $path): void
    {
        if (!str_starts_with($path, $this->root . '/')) {
            throw new RuntimeException("Module path is outside application root: $path");
        }
    }
}
