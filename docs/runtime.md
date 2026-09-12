# Runtime lifecycle

Public methods wait automatically. Use `Amp\async()` for concurrent operations;
PHP callbacks may suspend and call the client because they run after native
QuickJS dispatch returns.

## Timeouts and cancellation

Use Puppeteer's operation options (`timeout`) and connection option
`protocolTimeout`, in milliseconds. An ordinary Puppeteer timeout or JavaScript
exception rejects that operation; the connection can still be used.

The internal `Client` additionally accepts `Amp\Cancellation` for connecting and
dispatching calls. Cancellation closes the whole transport and rejects its pending
operations: it does not pretend to undo JavaScript already sent to Chrome.
This is an internal facility, not a PHP replacement for Puppeteer's `AbortSignal`.
Public `signal` options remain unsupported.

Transport failure and explicit disconnect clear pending calls, host timers and
bridge registries. A client cannot reconnect; create another `Puppeteer`
connection. The native QuickJS execution limit also applies to synchronous JS;
it cannot interrupt a blocking PHP callback.

## Events

`on()`, `once()` and `off()` preserve PHP handler identity across emitters.
Removing the final registration releases its PHP callback reference; a running
callback remains alive until it finishes. Closing or releasing an emitter also
removes its tracked handlers. Event callback failures are logged to stderr;
callbacks that return a value to JavaScript reject their JS promise on failure.

Callbacks passed to non-event APIs, such as `exposeFunction()`, are conservatively
retained until the connection closes. Do not repeatedly register disposable
callbacks on a long-lived connection expecting automatic removal.

## Object ownership

Close pages and contexts when their browser resources are no longer needed.
Call `dispose()` on JS handles. `release()` only drops the bridge reference; it
does not close a page or dispose a browser handle. Repeated `release()` is safe;
using a released wrapper or passing it to another client is rejected.

Collected PHP wrappers schedule bridge release on the event loop, outside native
callbacks. Keep explicit `close()`/`dispose()` in `finally` blocks for predictable
browser cleanup. `Browser::close()` also cleans up the process and temporary
profile owned by local `launch()`, including when the remote call fails.
`disconnect()` leaves the browser running.

## Values and errors

Ordinary data containing a `$quickjs` key is escaped by the codec and round trips
without being interpreted as a transport object. Cyclic/deep PHP arguments and
cyclic plain JS results are rejected. Invalid local arguments do not invalidate
an otherwise healthy connection.

The [extension contract](extension-contract.md) describes native conversion and
queue limits. [Integration tests](../tests/Integration/RuntimeLifecycleTest.php)
exercise runtime failure boundaries; [browser tests](../tests/Browser/runtime-lifecycle.php)
cover real timeouts, callback reentry and page shutdown.
