import './abort-controller.js';
import {emit} from './bridge.js';
import {ReadableStream} from 'web-streams-polyfill';
import 'core-js/actual/url/index.js';
import 'core-js/actual/url-search-params/index.js';
import 'core-js/actual/structured-clone.js';
import 'core-js/actual/set-immediate.js';
import 'core-js/actual/clear-immediate.js';

if (typeof globalThis.ReadableStream === 'undefined') globalThis.ReadableStream = ReadableStream;

// QuickJS has no Encoding Web API; Puppeteer decodes complete response bodies.
// Keep this UTF-8-only codec: in our QuickJS benchmarks @kayahr/text-encoding
// was 4-7x slower at encoding and 2-10x slower at decoding than the original codec.
// fast-text-encoding lacks fatal decoding and encodeInto, and its JS fallback
// drops lone high surrogates. Native implementations are preserved below.
// Streaming calls retain only an incomplete UTF-8 scalar (at most three bytes).
class QuickTextEncoder {
  get encoding() { return 'utf-8'; }
  encode(input = '') {
    const source = String(input);
    let ascii = true;
    for (let index = 0; index < source.length; index++) {
      if (source.charCodeAt(index) > 0x7f) {
        ascii = false;
        break;
      }
    }
    if (ascii) {
      const bytes = new Uint8Array(source.length);
      for (let index = 0; index < source.length; index++) bytes[index] = source.charCodeAt(index);
      return bytes;
    }
    const bytes = new Uint8Array(source.length * 3);
    const {written} = this.encodeInto(source, bytes);
    return bytes.subarray(0, written);
  }
  encodeInto(source, destination) {
    if (!(destination instanceof Uint8Array)) throw new TypeError('Expected Uint8Array');
    const input = String(source);
    let read = 0;
    let written = 0;
    const safeEnd = destination.length - 4;
    for (; read < input.length; read++) {
      let codePoint = input.codePointAt(read);
      if (codePoint >= 0xd800 && codePoint <= 0xdfff) codePoint = 0xfffd;
      if (written > safeEnd) {
        const length = codePoint <= 0x7f ? 1 : codePoint <= 0x7ff ? 2 : codePoint <= 0xffff ? 3 : 4;
        if (written + length > destination.length) break;
      }
      if (codePoint <= 0x7f) destination[written++] = codePoint;
      else if (codePoint <= 0x7ff) {
        destination[written++] = 0xc0 | (codePoint >> 6);
        destination[written++] = 0x80 | (codePoint & 0x3f);
      } else if (codePoint <= 0xffff) {
        destination[written++] = 0xe0 | (codePoint >> 12);
        destination[written++] = 0x80 | ((codePoint >> 6) & 0x3f);
        destination[written++] = 0x80 | (codePoint & 0x3f);
      } else {
        destination[written++] = 0xf0 | (codePoint >> 18);
        destination[written++] = 0x80 | ((codePoint >> 12) & 0x3f);
        destination[written++] = 0x80 | ((codePoint >> 6) & 0x3f);
        destination[written++] = 0x80 | (codePoint & 0x3f);
        read++;
      }
    }
    // Encoding Web API reports UTF-16 code units consumed, not UTF-8 bytes.
    return {read, written};
  }
}

class QuickTextDecoder {
  constructor(label = 'utf-8', options = {}) {
    if (String(label).toLowerCase() !== 'utf-8' && String(label).toLowerCase() !== 'utf8') throw new RangeError(`Unsupported encoding: ${label}`);
    this.fatal = Boolean(options?.fatal);
    this.ignoreBOM = Boolean(options?.ignoreBOM);
    this.streaming = false;
    this.pending = null;
    this.bomSeen = false;
  }
  get encoding() { return 'utf-8'; }
  decode(input = new Uint8Array(), options = {}) {
    let bytes = input instanceof Uint8Array ? input
      : ArrayBuffer.isView(input) ? new Uint8Array(input.buffer, input.byteOffset, input.byteLength)
      : new Uint8Array(input);
    if (!this.streaming) {
      this.pending = null;
      this.bomSeen = false;
    }
    this.streaming = Boolean(options?.stream);
    if (this.pending) {
      const joined = new Uint8Array(this.pending.length + bytes.length);
      joined.set(this.pending);
      joined.set(bytes, this.pending.length);
      bytes = joined;
      this.pending = null;
    }
    const decoded = this.decodeBytes(bytes);
    if (!decoded.length || this.bomSeen) return decoded;
    this.bomSeen = true;
    return !this.ignoreBOM && decoded.charCodeAt(0) === 0xfeff ? decoded.slice(1) : decoded;
  }
  decodeBytes(bytes) {
    let ascii = true;
    for (let index = 0; index < bytes.length; index++) {
      if (bytes[index] > 0x7f) {
        ascii = false;
        break;
      }
    }
    if (ascii) {
      const chunks = [];
      for (let index = 0; index < bytes.length; index += 32768) {
        chunks.push(String.fromCharCode.apply(null, bytes.subarray(index, index + 32768)));
      }
      return chunks.join('');
    }
    // Batch UTF-16 output to avoid a string allocation for each decoded scalar.
    // Bound apply() arguments to the same safe size as the ASCII path.
    const output = new Uint16Array(Math.min(bytes.length, 32768));
    const chunks = [];
    let written = 0;
    for (let index = 0; index < bytes.length;) {
      const first = bytes[index++];
      let codePoint;
      if (first <= 0x7f) codePoint = first;
      else if (first >= 0xc2 && first <= 0xdf) {
        const second = bytes[index];
        if ((second & 0xc0) === 0x80) {
          index++;
          codePoint = ((first & 0x1f) << 6) | (second & 0x3f);
        } else if (index === bytes.length && this.streaming) {
          this.pending = bytes.slice(index - 1);
          break;
        } else codePoint = this.invalid();
      } else if (first >= 0xe0 && first <= 0xf4) {
        const width = first <= 0xef ? 2 : 3;
        const second = bytes[index];
        const third = bytes[index + 1];
        const fourth = bytes[index + 2];
        const lower = first === 0xe0 ? 0xa0 : first === 0xf0 ? 0x90 : 0x80;
        const upper = first === 0xed ? 0x9f : first === 0xf4 ? 0x8f : 0xbf;
        const valid = second >= lower && second <= upper
          && (third & 0xc0) === 0x80
          && (width < 3 || (fourth & 0xc0) === 0x80);
        if (valid) {
          codePoint = width === 2 ? ((first & 0x0f) << 12) | ((second & 0x3f) << 6) | (third & 0x3f)
            : ((first & 7) << 18) | ((second & 0x3f) << 12) | ((third & 0x3f) << 6) | (fourth & 0x3f);
          index += width;
        } else {
          // Consume the valid prefix, but reprocess an offending byte. This
          // gives the same replacement characters regardless of chunk splits.
          let consumed = 0;
          while (consumed < width && index < bytes.length) {
            const next = bytes[index];
            if (consumed === 0 ? next < lower || next > upper : (next & 0xc0) !== 0x80) break;
            index++;
            consumed++;
          }
          if (consumed < width && index === bytes.length && this.streaming) {
            // Copy rather than retain the caller's potentially large buffer.
            this.pending = bytes.slice(index - consumed - 1);
            break;
          }
          codePoint = this.invalid();
        }
      } else codePoint = this.invalid();
      if (codePoint <= 0xffff) output[written++] = codePoint;
      else {
        codePoint -= 0x10000;
        output[written++] = 0xd800 | (codePoint >> 10);
        output[written++] = 0xdc00 | (codePoint & 0x3ff);
      }
      if (written >= output.length - 1) {
        chunks.push(String.fromCharCode.apply(null, output.subarray(0, written)));
        written = 0;
      }
    }
    if (written) chunks.push(String.fromCharCode.apply(null, output.subarray(0, written)));
    return chunks.join('');
  }
  invalid() { if (this.fatal) throw new TypeError('The encoded data was not valid UTF-8'); return 0xfffd; }
}

if (typeof globalThis.TextEncoder === 'undefined') globalThis.TextEncoder = QuickTextEncoder;
if (typeof globalThis.TextDecoder === 'undefined') globalThis.TextDecoder = QuickTextDecoder;

// Host services only. Puppeteer's browser logic remains upstream JavaScript.
let nextTimer = 0;
const timers = new Map();
globalThis.setTimeout = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  timers.set(id, {fn: () => fn(...args), interval: null});
  emit('timer', {id, milliseconds: Math.max(0, Number(milliseconds) || 0)});
  return id;
};
globalThis.clearTimeout = id => {
  timers.delete(id);
  emit('clearTimer', String(id));
};
globalThis.setInterval = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  const interval = Math.max(1, Number(milliseconds) || 0);
  timers.set(id, {fn: () => fn(...args), interval});
  emit('timer', {id, milliseconds: interval});
  return id;
};
globalThis.clearInterval = globalThis.clearTimeout;
globalThis.performance = {now: () => php.now()};
const logLevels = {log: 'info', warn: 'warning', error: 'error', debug: 'debug', info: 'info'};
globalThis.console = Object.fromEntries(Object.entries(logLevels).map(([method, level]) => [method, (...args) => emit('log', {level, message: args.map(String).join(' ')})]));
export function fireTimer(id) {
  const timer = timers.get(id);
  if (!timer) return;
  if (timer.interval === null) timers.delete(id);
  timer.fn();
  if (timer.interval !== null && timers.has(id)) {
    emit('timer', {id, milliseconds: timer.interval});
  }
}

// Keep core-js's pending callback queue bounded by this client's lifetime.
const immediates = new Set();
const scheduleImmediate = globalThis.setImmediate;
const cancelImmediate = globalThis.clearImmediate;
globalThis.setImmediate = (fn, ...args) => {
  const id = scheduleImmediate(() => { immediates.delete(id); fn(...args); });
  immediates.add(id);
  return id;
};
globalThis.clearImmediate = id => {
  immediates.delete(id);
  cancelImmediate(id);
};

export function clearTimers() {
  for (const id of immediates) cancelImmediate(id);
  immediates.clear();
  timers.clear();
}
