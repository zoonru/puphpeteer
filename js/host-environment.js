import {ReadableStream} from 'web-streams-polyfill';

globalThis.ReadableStream = ReadableStream;

// QuickJS does not provide the Encoding Web API. Puppeteer uses UTF-8
// TextEncoder/TextDecoder when transferring intercepted response bodies.
class QuickTextEncoder {
  get encoding() { return 'utf-8'; }
  encode(input = '') {
    const source = String(input);
    const bytes = [];
    for (let index = 0; index < source.length; index++) {
      let codePoint = source.charCodeAt(index);
      if (codePoint >= 0xd800 && codePoint <= 0xdbff) {
        const low = source.charCodeAt(index + 1);
        if (low >= 0xdc00 && low <= 0xdfff) {
          codePoint = 0x10000 + ((codePoint - 0xd800) << 10) + low - 0xdc00;
          index++;
        } else {
          codePoint = 0xfffd;
        }
      } else if (codePoint >= 0xdc00 && codePoint <= 0xdfff) {
        codePoint = 0xfffd;
      }
      if (codePoint <= 0x7f) bytes.push(codePoint);
      else if (codePoint <= 0x7ff) bytes.push(0xc0 | (codePoint >> 6), 0x80 | (codePoint & 0x3f));
      else if (codePoint <= 0xffff) bytes.push(0xe0 | (codePoint >> 12), 0x80 | ((codePoint >> 6) & 0x3f), 0x80 | (codePoint & 0x3f));
      else bytes.push(0xf0 | (codePoint >> 18), 0x80 | ((codePoint >> 12) & 0x3f), 0x80 | ((codePoint >> 6) & 0x3f), 0x80 | (codePoint & 0x3f));
    }
    return Uint8Array.from(bytes);
  }
  encodeInto(source, destination) {
    const input = String(source);
    let read = 0;
    let written = 0;
    while (read < input.length) {
      let next = read + 1;
      const high = input.charCodeAt(read);
      if (high >= 0xd800 && high <= 0xdbff && input.charCodeAt(next) >= 0xdc00 && input.charCodeAt(next) <= 0xdfff) next++;
      const encoded = this.encode(input.slice(read, next));
      if (written + encoded.length > destination.length) break;
      destination.set(encoded, written);
      written += encoded.length;
      read = next;
    }
    // Encoding Web API reports UTF-16 code units consumed, not UTF-8 bytes.
    return {read, written};
  }
}

class QuickTextDecoder {
  constructor(label = 'utf-8', options = {}) {
    if (String(label).toLowerCase() !== 'utf-8' && String(label).toLowerCase() !== 'utf8') throw new RangeError(`Unsupported encoding: ${label}`);
    this.fatal = Boolean(options.fatal);
    this.ignoreBOM = Boolean(options.ignoreBOM);
  }
  get encoding() { return 'utf-8'; }
  decode(input = new Uint8Array()) {
    const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
    let output = '';
    for (let index = 0; index < bytes.length;) {
      const first = bytes[index++];
      let codePoint;
      let width;
      if (first <= 0x7f) { codePoint = first; width = 0; }
      else if (first >= 0xc2 && first <= 0xdf) { codePoint = first & 0x1f; width = 1; }
      else if (first >= 0xe0 && first <= 0xef) { codePoint = first & 0x0f; width = 2; }
      else if (first >= 0xf0 && first <= 0xf4) { codePoint = first & 0x07; width = 3; }
      else { output += this.invalid(); continue; }
      if (width) {
        const start = index;
        let valid = index + width <= bytes.length;
        for (let offset = 0; valid && offset < width; offset++) valid = bytes[index + offset] >= 0x80 && bytes[index + offset] <= 0xbf;
        if (valid) {
          for (let offset = 0; offset < width; offset++) codePoint = (codePoint << 6) | (bytes[index++] & 0x3f);
          valid = (width === 1 && codePoint >= 0x80)
            || (width === 2 && codePoint >= 0x800 && !(codePoint >= 0xd800 && codePoint <= 0xdfff))
            || (width === 3 && codePoint >= 0x10000 && codePoint <= 0x10ffff);
        }
        if (!valid) { index = start; index++; output += this.invalid(); continue; }
      }
      if (codePoint <= 0xffff) output += String.fromCharCode(codePoint);
      else { codePoint -= 0x10000; output += String.fromCharCode(0xd800 | (codePoint >> 10), 0xdc00 | (codePoint & 0x3ff)); }
    }
    if (!this.ignoreBOM && output.charCodeAt(0) === 0xfeff) output = output.slice(1);
    return output;
  }
  invalid() { if (this.fatal) throw new TypeError('The encoded data was not valid UTF-8'); return '\ufffd'; }
}

if (typeof globalThis.TextEncoder === 'undefined') globalThis.TextEncoder = QuickTextEncoder;
if (typeof globalThis.TextDecoder === 'undefined') globalThis.TextDecoder = QuickTextDecoder;

// Host services only. Puppeteer's browser logic remains upstream JavaScript.
let nextTimer = 0;
const timers = new Map();
globalThis.setTimeout = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  timers.set(id, {fn: () => fn(...args), interval: null});
  __quickjsEmit('timer', {id, milliseconds: Math.max(0, Number(milliseconds) || 0)});
  return id;
};
globalThis.clearTimeout = id => {
  timers.delete(id);
  __quickjsEmit('clearTimer', String(id));
};
globalThis.setInterval = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  const interval = Math.max(1, Number(milliseconds) || 0);
  timers.set(id, {fn: () => fn(...args), interval});
  __quickjsEmit('timer', {id, milliseconds: interval});
  return id;
};
globalThis.clearInterval = globalThis.clearTimeout;
globalThis.performance = {now: () => php.now()};
globalThis.console = Object.fromEntries(['log', 'warn', 'error', 'debug', 'info'].map(level => [level, (...args) => __quickjsEmit('log', args.map(String).join(' '))]));
export function fireTimer(id) {
  const timer = timers.get(id);
  if (!timer) return;
  if (timer.interval === null) timers.delete(id);
  timer.fn();
  if (timer.interval !== null && timers.has(id)) {
    __quickjsEmit('timer', {id, milliseconds: timer.interval});
  }
}

export function clearTimers() { timers.clear(); }
