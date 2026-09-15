// QuickJS host support used by Puppeteer's WaitTask when restarting after navigation.
// User-supplied AbortSignal arguments remain outside the PHP transport contract.
class QuickAbortSignal {
  #aborted = false;
  #reason;
  #listeners = new Map();
  onabort = null;
  get aborted() { return this.#aborted; }
  get reason() { return this.#reason; }
  throwIfAborted() { if (this.#aborted) throw this.#reason; }
  addEventListener(type, callback, options = {}) {
    if (type !== 'abort' || callback == null || this.#listeners.has(callback)) return;
    this.#listeners.set(callback, !!options?.once);
  }
  removeEventListener(type, callback) { if (type === 'abort') this.#listeners.delete(callback); }
  abort(reason) {
    if (this.#aborted) return;
    this.#reason = reason === undefined ? Object.assign(new Error('This operation was aborted'), {name:'AbortError'}) : reason;
    this.#aborted = true;
    const event = {type:'abort', target:this, currentTarget:this};
    for (const [callback, once] of [...this.#listeners]) {
      if (!this.#listeners.has(callback)) continue;
      if (once) this.#listeners.delete(callback);
      try {
        if (typeof callback === 'function') callback.call(this, event);
        else callback.handleEvent(event);
      } catch (error) { console.error(error); }
    }
    if (typeof this.onabort === 'function') {
      try { this.onabort.call(this, event); } catch (error) { console.error(error); }
    }
    this.#listeners.clear();
  }
}
class QuickAbortController {
  #signal = new QuickAbortSignal();
  get signal() { return this.#signal; }
  abort(reason) { this.#signal.abort(reason); }
}
if (typeof globalThis.AbortController === 'undefined') globalThis.AbortController = QuickAbortController;
