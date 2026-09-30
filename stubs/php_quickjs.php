<?php

namespace {
    // Stubs for the php-quickjs extension (IDE / static-analysis aid only).
    // These declarations describe the native classes; they are not loaded at
    // runtime. Regenerate with `make stubs` (requires cargo-php).

    /**
     * An embedded QuickJS sandbox with a typed, bidirectional PHP bridge.
     */
    class QuickJS
    {
        /**
         * @param int|null $memoryLimit              max heap bytes (0/null = unbounded)
         * @param int|null $timeoutMs                per-eval/callback/job-batch wall-clock budget in ms (0/null = unbounded)
         * @param int|null $maxStack                 max native stack bytes (0/null = engine default)
         * @param bool     $isolated                 run each eval() in its own fresh global realm
         * @param int|null $maxQueuedMessageBytes    max accounted bytes in the message queue (null = 32 MiB)
         * @param int      $transpileCacheMaxBytes   retained cache string bytes (0 disables cache)
         * @param int      $transpileCacheMaxEntries retained cache entries (0 disables cache)
         */
        public function __construct(?int $memoryLimit = null, ?int $timeoutMs = null, ?int $maxStack = null, bool $isolated = false, ?int $maxQueuedMessageBytes = null, int $transpileCacheMaxBytes = 33554432, int $transpileCacheMaxEntries = 256)
        {
        }

        /**
         * Register a PHP callable under a flat, dotted capability name, callable
         * from JS as `php.<dotted.name>(...)`.
         *
         * @param string      $name     e.g. "db.query"
         * @param callable    $callable
         * @param string|null $types    optional TypeScript signature for `dts()`
         */
        public function register(string $name, mixed $callable, ?string $types = null): void
        {
        }

        /**
         * Evaluate source and marshal the result back to PHP, awaiting a returned Promise.
         * TypeScript is transpiled by default; false executes JavaScript directly.
         */
        public function eval(string $code, bool $typescript = true): mixed
        {
        }

        /**
         * The registration manifest.
         *
         * @return list<array{name: string, types: ?string}>
         */
        public function manifest(): mixed
        {
        }

        /** Whether Promise jobs are ready (shared mode only). Does not include host I/O. */
        public function hasPendingJobs(): bool
        {
        }

        /** Execute at most maxJobs ready jobs without waiting for I/O; returns the count. */
        public function executePendingJobs(int $maxJobs = 100): int
        {
        }

        /**
         * Drain bounded messages emitted by quickjs.postMessage() without entering JS.
         *
         * @return list<mixed>
         */
        public function drainMessages(): mixed
        {
        }

        /** Generate TypeScript `.d.ts` declarations for the `php` and `quickjs` globals. */
        public function dts(): string
        {
        }

        /** Grant JS an opaque integer handle to a live PHP value. */
        public function grant(mixed $resource): int
        {
        }

        /** Resolve a handle back to its live PHP value (throws if unknown). */
        public function resolve(int $handle): mixed
        {
        }

        /** Revoke a handle, releasing the host-side reference. */
        public function revoke(int $handle): bool
        {
        }

        /** Round-trip a PHP value through JS and back (testing/diagnostics). */
        public function roundtrip(mixed $value): mixed
        {
        }
    }
}

namespace Js {
    /**
     * A JS function handed to PHP. Invoke it like any callable: `$cb(...$args)`.
     */
    class Callback
    {
        /** Native constructor exists, but callbacks are created only by the bridge. */
        public function __construct()
        {
        }

        /** Invoke the JS function, awaiting a returned Promise. */
        public function __invoke(mixed ...$args): mixed
        {
        }

        /** Invoke the JS function, awaiting a returned Promise. */
        public function call(mixed ...$args): mixed
        {
        }
    }
}

namespace {
    /** Base class for every exception thrown by the extension. */
    class QuickJSException extends Exception
    {
        /** Native constructor exists; exceptions are created by the extension. */
        public function __construct()
        {
        }
    }

    /**
     * A JavaScript/TypeScript error escaped `eval`. `getMessage()` is the clean
     * error text and `getFile()`/`getLine()` carry the original source location
     * (guest.ts after remapping, or guest.js with typescript: false).
     */
    class QuickJSEvalException extends QuickJSException
    {
        /** Native constructor exists; exceptions are created by the extension. */
        public function __construct()
        {
        }

        /** The JS error constructor name (e.g. "TypeError"), or the PHP class for a re-surfaced host error. */
        public function getJsName(): string
        {
        }

        /** The remapped guest-only TypeScript stack, or the original direct JavaScript stack. */
        public function getJsStack(): string
        {
        }
    }

    /** The wall-clock deadline tripped during `eval`. */
    class QuickJSTimeoutException extends QuickJSException
    {
        /** Native constructor exists; exceptions are created by the extension. */
        public function __construct()
        {
        }
    }

    /** The memory limit tripped during `eval`. */
    class QuickJSMemoryException extends QuickJSException
    {
        /** Native constructor exists; exceptions are created by the extension. */
        public function __construct()
        {
        }
    }
}
