import {AbortController as Controller, AbortSignal as Signal} from 'abort-controller/dist/abort-controller.mjs';
import 'core-js/actual/dom-exception/index.js';

// EventTarget/listener semantics come from abort-controller + event-target-shim.
// Add the modern cancellation API without replacing native implementations.
if (typeof globalThis.AbortController === 'undefined') {
  const reasons = new WeakMap();
  class HostAbortController extends Controller {
    abort(reason = new DOMException('This operation was aborted', 'AbortError')) {
      if (this.signal.aborted) return;
      reasons.set(this.signal, reason);
      super.abort();
    }
  }
  Object.defineProperty(Signal.prototype, 'reason', {get() { return reasons.get(this); }});
  Signal.prototype.throwIfAborted = function () { if (this.aborted) throw this.reason; };
  Signal.abort = reason => {
    const controller = new HostAbortController();
    controller.abort(reason);
    return controller.signal;
  };
  Signal.timeout = milliseconds => {
    if (!Number.isSafeInteger(milliseconds) || milliseconds < 0) throw new RangeError('Invalid timeout');
    const controller = new HostAbortController();
    setTimeout(() => controller.abort(new DOMException('The operation timed out', 'TimeoutError')), milliseconds);
    return controller.signal;
  };
  Signal.any = iterable => {
    if (iterable == null || typeof iterable[Symbol.iterator] !== 'function') throw new TypeError('Expected an iterable');
    const signals = [...new Set(iterable)];
    for (const signal of signals) if (!(signal instanceof Signal)) throw new TypeError('Expected AbortSignal');
    const controller = new HostAbortController();
    const aborted = signals.find(signal => signal.aborted);
    if (aborted) controller.abort(aborted.reason);
    else {
      const abort = event => {
        for (const signal of signals) signal.removeEventListener('abort', abort);
        controller.abort(event.target.reason);
      };
      for (const signal of signals) signal.addEventListener('abort', abort, {once: true});
    }
    return controller.signal;
  };
  globalThis.AbortController = HostAbortController;
  globalThis.AbortSignal = Signal;
}
