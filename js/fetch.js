import {Headers} from './headers.js';
import {emit} from './bridge.js';

const unsupported = message => new DOMException(message, 'NotSupportedError');
const redirects = new Set([301, 302, 303, 307, 308]);
function byteView(value) {
  return value instanceof ArrayBuffer ? new Uint8Array(value)
    : new Uint8Array(value.buffer, value.byteOffset, value.byteLength);
}

class Body {
  constructor(body, headers) {
    this.headers = new Headers(headers);
    this._streamUpload = body instanceof ReadableStream;
    if (body != null && !this._streamUpload) {
      let bytes;
      if (body instanceof ArrayBuffer || ArrayBuffer.isView(body)) bytes = byteView(body).slice();
      else {
        const form = body instanceof URLSearchParams;
        if (typeof body !== 'string' && !form) throw unsupported('Unsupported body type');
        bytes = new TextEncoder().encode(String(body));
        if (!this.headers.has('content-type')) this.headers.set('content-type', form
          ? 'application/x-www-form-urlencoded;charset=UTF-8' : 'text/plain;charset=UTF-8');
      }
      body = bytes;
    }
    this._setBody(body ?? null);
  }
  _setBody(source) {
    const state = this._state = {used: false};
    let reader;
    this.body = source === null ? null : new ReadableStream({
      async pull(controller) {
        state.used = true;
        try {
          if (source instanceof Uint8Array) { controller.enqueue(source); controller.close(); return; }
          reader ??= source.getReader();
          const {value, done} = await reader.read();
          if (done) { reader.releaseLock(); controller.close(); }
          else {
            if (!(value instanceof Uint8Array)) throw new TypeError('Expected a byte stream');
            controller.enqueue(value);
          }
        } catch (error) { controller.error(error); await reader?.cancel(error).catch(() => {}); }
      },
      cancel(reason) { state.used = true; return reader ? reader.cancel(reason) : source.cancel?.(reason); },
    }, {highWaterMark: 0});
    if (this.body) {
      const getReader = this.body.getReader.bind(this.body);
      this.body.getReader = options => {
        const reader = getReader(options);
        for (const method of ['read', 'cancel']) {
          const call = reader[method].bind(reader);
          reader[method] = (...args) => { state.used = true; return call(...args); };
        }
        return reader;
      };
    }
  }
  get bodyUsed() { return this._state.used; }
  _assertUnused() {
    if (this.bodyUsed || this.body?.locked) throw new TypeError('Body has already been consumed');
  }
  _cloneBody() {
    this._assertUnused();
    if (!this.body) return null;
    const [left, right] = this.body.tee();
    this._setBody(left);
    return right;
  }
  async _consume(text) {
    this._assertUnused();
    if (!this.body) return text ? '' : new ArrayBuffer(0);
    this._state.used = true;
    const decoder = text ? new TextDecoder() : null;
    const chunks = [];
    let size = 0;
    for await (const value of this.body) {
      chunks.push(text ? decoder.decode(value, {stream: true}) : value);
      size += value.byteLength;
    }
    if (text) return chunks.join('') + decoder.decode();
    const output = new Uint8Array(size);
    let offset = 0;
    for (const chunk of chunks) { output.set(chunk, offset); offset += chunk.length; }
    return output.buffer;
  }
  text() { return this._consume(true); }
  arrayBuffer() { return this._consume(false); }
  async json() { return JSON.parse(await this.text()); }
  async blob() { throw unsupported('Blob is not supported by host fetch'); }
  async formData() { throw unsupported('FormData is not supported by host fetch'); }
}

class Request extends Body {
  constructor(input, init = {}) {
    init ??= {};
    const original = input instanceof Request ? input : null;
    const url = new URL(original ? original.url : String(input));
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new TypeError('Expected an HTTP(S) URL without credentials');
    const method = String(init.method ?? original?.method ?? 'GET');
    const upper = method.toUpperCase();
    if (!/^[!#$%&'*+.^_`|~0-9a-z-]+$/i.test(method) || ['CONNECT', 'TRACE', 'TRACK'].includes(upper)) throw new TypeError('Invalid HTTP method');
    const body = init.body !== undefined ? init.body : original?.body ?? null;
    if (body !== null && ['GET', 'HEAD'].includes(upper)) throw new TypeError('GET/HEAD requests cannot have a body');
    original?._assertUnused();
    super(body, init.headers ?? original?.headers);
    url.hash = '';
    this.url = url.href;
    this.method = ['DELETE', 'GET', 'HEAD', 'OPTIONS', 'POST', 'PUT'].includes(upper) ? upper : method;
    this.signal = init.signal ?? original?.signal ?? new AbortController().signal;
    if (!(this.signal instanceof AbortSignal)) throw new TypeError('Expected AbortSignal');
    this.redirect = init.redirect ?? original?.redirect ?? 'follow';
    if (!['follow', 'manual', 'error'].includes(this.redirect)) throw new TypeError('Invalid redirect mode');
    this._streamUpload = original && init.body === undefined ? original._streamUpload : this._streamUpload;
  }
  clone() { return new Request(this, {body: this._cloneBody()}); }
}

class Response extends Body {
  constructor(body = null, init = {}) {
    init ??= {};
    const status = init.status ?? 200;
    if (!Number.isInteger(status) || status < 200 || status > 599) throw new RangeError('Invalid response status');
    if ([204, 205, 304].includes(status) && body !== null) throw new TypeError('This status cannot have a body');
    if (/[\r\n]/.test(String(init.statusText ?? ''))) throw new TypeError('Invalid status text');
    super(body, init.headers);
    this.status = status;
    this.statusText = String(init.statusText ?? '');
    this.url = '';
    this.redirected = false;
    this.type = 'default';
  }
  get ok() { return this.status >= 200 && this.status < 300; }
  clone() {
    return Object.assign(new Response(this._cloneBody(), this), {
      url: this.url, redirected: this.redirected, type: this.type,
    });
  }
  static error() { return Object.assign(new Response(), {status: 0, type: 'error'}); }
  static redirect(url, status = 302) {
    if (!redirects.has(status)) throw new RangeError('Invalid redirect status');
    return new Response(null, {status, headers: {location: new URL(url).href}});
  }
  static json(value, init = {}) {
    const headers = new Headers(init.headers);
    if (!headers.has('content-type')) headers.set('content-type', 'application/json');
    const body = JSON.stringify(value);
    if (body === undefined) throw new TypeError('Value is not JSON serializable');
    return new Response(body, {...init, headers});
  }
}

const active = new Set();
let nextRequest = 0;
export function closeFetch() {
  for (const close of [...active]) close(new Error('PHP transport closed'));
  active.clear();
}
export function installFetch(hostRequest) {
  if (typeof globalThis.fetch !== 'undefined') return;
  globalThis.Headers ??= Headers;
  globalThis.Request ??= Request;
  globalThis.Response ??= Response;
  globalThis.fetch = async (input, init) => {
    const request = input instanceof Request && init === undefined ? input : new Request(input, init);
    const signal = request.signal;
    signal.throwIfAborted();
    if (request._streamUpload) throw unsupported('Streaming request uploads are not supported');
    const token = ++nextRequest;
    let controller;
    let rejectAbort;
    let finished = false;
    const aborted = new Promise((_, reject) => { rejectAbort = reject; });
    // The rejection is also used for aborts after fetch has resolved.
    aborted.catch(() => {});
    const cleanup = () => { finished = true; active.delete(close); signal.removeEventListener('abort', onAbort); };
    const close = reason => {
      if (finished) return;
      cleanup();
      emit('httpCancel', token);
      controller?.error(reason);
      rejectAbort(reason);
    };
    const onAbort = () => close(signal.reason);
    active.add(close);
    signal.addEventListener('abort', onAbort, {once: true});
    try {
      const body = request.body ? new Uint8Array(await Promise.race([request.arrayBuffer(), aborted])) : null;
      signal.throwIfAborted();
      const result = await Promise.race([hostRequest('start', token, {
        url: request.url, method: request.method, headers: [...request.headers].map(([name, value]) => [name, Uint8Array.from(value, char => char.charCodeAt(0))]), redirect: request.redirect, body,
      }), aborted]);
      signal.throwIfAborted();
      const stream = result.noBody ? null : new ReadableStream({
        start(value) { controller = value; },
        async pull(value) {
          try {
            const chunk = await hostRequest('read', token, null);
            if (finished) return;
            if (chunk === null) { cleanup(); value.close(); }
            else value.enqueue(chunk.subarray(1));
          } catch (error) { close(signal.aborted ? signal.reason : error); }
        },
        async cancel() {
          if (finished) return;
          cleanup();
          await hostRequest('close', token, null);
        },
      }, {highWaterMark: 0});
      const response = new Response(stream, result);
      Object.assign(response, {url: result.url, redirected: result.redirected, type: 'basic'});
      if (result.noBody) cleanup();
      return response;
    } catch (error) { close(error); throw signal.aborted ? signal.reason : error; }
  };
}
