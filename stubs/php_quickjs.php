<?php

namespace {
    // Stubs for the php-quickjs extension (IDE / static-analysis aid only).
    // These declarations describe the native classes; they are not loaded at
    // runtime. Contract: README.md#extension-contract. Keep in sync with the
    // hardened https://github.com/xtrime-ru/php-quickjs fork.

    /**
     * An embedded QuickJS sandbox with a typed, bidirectional PHP bridge.
     */
    class QuickJS
    {
        /**
         * @param int|null $memoryLimit           max heap bytes (0/null = unbounded)
         * @param int|null $timeoutMs             per-eval/callback/job-batch wall-clock budget in ms (0/null = unbounded)
         * @param int|null $maxStack              max native stack bytes (0/null = engine default)
         * @param bool     $isolated              run each eval() in its own fresh global realm
         * @param int|null $maxQueuedMessageBytes max accounted bytes in the native message queue (null = 32 MiB)
         */
        public function __construct(?int $memoryLimit = null, ?int $timeoutMs = null, ?int $maxStack = null, bool $isolated = false, ?int $maxQueuedMessageBytes = null)
        {
        }

        /**
         * Register a PHP callable under a flat, dotted capability name, callable
         * from JS as `php.<dotted.name>(...)`.
         *
         * @param string      $name  e.g. "db.query"
         * @param string|null $types optional TypeScript signature for `dts()`
         */
        public function register(string $name, callable $callable, ?string $types = null): void
        {
        }

        /** Evaluate JS, automatically await Promise/thenable results and marshal the resolved value. */
        public function eval(string $code): mixed
        {
        }

        /** The registration manifest: a list of `['name' => string, 'types' => ?string]`. */
        public function manifest(): array
        {
        }

        /** Whether Promise jobs are ready (shared mode only). Does not include host I/O. */
        public function hasPendingJobs(): bool
        {
        }

        /** Execute at most maxJobs (> 0) ready jobs without waiting for I/O; returns the count. */
        public function executePendingJobs(int $maxJobs = 100): int
        {
        }

        /** Drain bounded notifications emitted by quickjs.postMessage(). */
        public function drainMessages(): array
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
     * Promise/thenable results are awaited through Revolt; concurrent callbacks
     * are queued while the owning Fiber waits for external I/O.
     */
    class Callback
    {
        public function __invoke(mixed ...$args): mixed
        {
        }

        public function call(mixed ...$args): mixed
        {
        }
    }
}

namespace {
    /** Base class for every exception thrown by the extension. */
    class QuickJSException extends Exception
    {
    }

    /**
     * A JavaScript/TypeScript error escaped `eval`. `getMessage()` is the clean
     * error text and `getFile()`/`getLine()` carry the original TS location.
     */
    class QuickJSEvalException extends QuickJSException
    {
        /** The JS error constructor name (e.g. "TypeError"), or the PHP class for a re-surfaced host error. */
        public function getJsName(): string
        {
        }

        /** The stack trace, remapped to TypeScript coordinates and filtered to guest frames. */
        public function getJsStack(): string
        {
        }
    }

    /** The wall-clock deadline tripped during `eval`. */
    class QuickJSTimeoutException extends QuickJSException
    {
    }

    /** The memory limit tripped during `eval`. */
    class QuickJSMemoryException extends QuickJSException
    {
    }
}
