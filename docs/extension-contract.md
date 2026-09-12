# PHP–QuickJS extension contract

PuPHPeteer requires the hardened [php-quickjs fork](https://github.com/xtrime-ru/php-quickjs) described in the [installation instructions](../README.md). The reviewed source revision is `3513e24acc1a8a0797e903f6637879580551e417` on `async-jobs-fibers` (not yet pushed). Upstream php-quickjs and older experimental builds are not interchangeable with this dependency. The extension currently has no capability/version marker for its safety guarantees: the presence of `Js\Callback::dispatch()` alone is insufficient. Run the integration suite against the extension binary being deployed.

```sh
php -n -d extension=/absolute/path/to/libphp_quickjs.so vendor/bin/phpunit tests/Integration
```

Use `.dylib` on macOS. This suite fails when the extension is absent or incompatible; it does not silently skip tests. It requires no Chrome or Node.js. Platform validation remains separate: local tests on macOS arm64 / PHP 8.5 NTS do not establish Linux, ZTS or other PHP-version support.

## Dispatch and scheduling

The client owns a shared-mode `QuickJS` instance and a `Js\Callback` dispatcher. `dispatch(?array $args, int $maxJobs = 100)`:

1. Invokes the saved JS function when `$args` is a positional list. `[]` means call without arguments; `null` means do not call it.
2. Ignores the function's return value. JS sends messages with `__quickjsEmit(stringKind, data)` instead.
3. Executes at most `$maxJobs` ready Promise jobs; the budget must be positive.
4. Returns `['messages' => list<[string, mixed]>, 'jobs' => int, 'pending' => bool]`. Messages retain emission order. `pending` describes ready JS jobs only, not PHP timers, sockets or unresolved Promises waiting for external input.

A drain consumes its messages. Further `dispatch(null)` calls resume pending jobs. Neither dispatch nor `executePendingJobs()` waits for host I/O. The PHP event loop performs that I/O and schedules another bounded batch. The client currently uses 100 jobs per batch.

A job count cannot interrupt one long synchronous callback or job. The engine timeout is therefore independent: the client uses a 2-second native execution budget, 256 MiB JS heap limit and 512 KiB stack limit. These limits do not bound PHP allocations, browser memory or the duration of PHP host functions. Protocol operation timeouts are a separate client concern.

## Direct data conversion

The transport uses native value conversion without the generic callback MessagePack round trip. It is copying conversion, not shared memory.

| Value | Representation |
| --- | --- |
| PHP `null`, bool, int, float | JS null, boolean, Number |
| PHP UTF-8 string, including NUL | JS string |
| PHP string with invalid UTF-8 bytes | JS Uint8Array |
| PHP list | JS Array |
| PHP associative array | JS object with string keys |
| JS undefined or null | PHP null |
| JS boolean, Number | PHP bool, int when integral and representable, otherwise float |
| JS string, Uint8Array | PHP string, preserving bytes for Uint8Array |
| JS Array, enumerable object properties | PHP arrays |

JS Number limits integer precision: values outside the safe integer range ±9,007,199,254,740,991 must not be used as exact transport integers. `2.0` can return as PHP int `2`. Empty JS arrays and objects both become PHP `[]`; this bridge does not preserve that distinction. Object properties can invoke getters, and getter exceptions propagate. Arbitrary JS prototypes and class identity are not serialized.

Direct emitted data cannot contain functions, symbols or BigInt. Cycles and excessive nesting are rejected. Generic extension callback APIs support callable conversion, but PuPHPeteer encodes PHP callbacks, `JsFunction` and remote objects as its own transport records; it does not emit native callable values. A plain object with a key such as `$__jsfn` remains data in the direct path and does not acquire MessagePack callback-tag semantics. Remote object identity and special JS values are handled by the bundle/client protocol above this layer.

## Limits and failure recovery

The extension enforces the following native bounds:

- At most 4,096 queued messages per batch.
- At most 32 MiB of accounted queue data, including kind and structural overhead.
- A 16 MiB conversion budget per JS output value, including a 64-byte charge per visited node and the bytes of keys/string/binary content. A 16 MiB binary payload therefore exceeds the budget.
- Nesting depth at most 64 in both conversion directions. PHP inputs do not have the JS-output byte budget; do not interpret output limits as a total PHP-input memory bound.

Over-limit or invalid values, getter errors, native timeouts and dispatch exceptions fail the batch. Partial output is discarded and the collection state resets so a later valid batch can succeed. This is not transaction rollback: JS mutations and queued jobs may survive an error. The client closes a failed transport instead of retrying a partially executed operation. Emission outside `dispatch()` is rejected.

## Ownership and Fibers

A saved `Js\Callback` retains its engine. Releasing its PHP wrapper queues deletion of its JS registry entry; deletion is deferred to a subsequent native execution boundary. It must not retain every discarded callback for the full engine lifetime. Opaque PHP handles created by `grant()` retain their values until `revoke()` or engine destruction; a revoked handle cannot resolve again.

The extension's generic PHP callable registry retains registered callables for the engine lifetime and exposes no individual unregister API. PuPHPeteer uses its own callback IDs and event registration instead. Per-listener PHP closure reclamation and transport shutdown belong to client lifecycle work; extension callback-wrapper release does not imply those client lifetimes are already bounded.

An engine and saved callbacks can be used on the main stack and on different PHP Fibers sequentially. Native entry updates QuickJS stack handling for the current Fiber. A Fiber cannot suspend while PHP is called from an active native JS execution; the extension rejects that switch. A nested `dispatch()` on the same active engine is also rejected. Ordinary synchronous JS-to-PHP-to-JS callbacks are a different supported path.

The PuPHPeteer dispatcher therefore queues PHP callback work and invokes it through `Amp\async()` after returning from native dispatch. Those PHP handlers may then suspend and call public client methods. This does not enable simultaneous execution of the same QuickJS runtime on multiple threads.

## Verification

`tests/Integration/BridgeTest.php` checks binary payloads, draining and bounded Promise-job continuation. `ExtensionContractTest.php` checks typed values and tag safety, argument validation, queue/size/depth failures, recovery without stale messages, callback and handle release, cross-Fiber use, rejected in-native switching/reentrancy and synchronous timeout recovery. Browser smoke tests separately cover the bundle/client boundary, events and reentrant PHP callbacks with real Puppeteer.
