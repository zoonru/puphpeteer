import {fireTimer} from './host-environment.js';
import puppeteer, {
  Accessibility, Browser, BrowserContext, CDPSession, ConsoleMessage, Coverage,
  Dialog, ElementHandle, FileChooser, Frame, HTTPRequest, HTTPResponse, JSHandle,
  Keyboard, Mouse, Page, SecurityDetails, Target, Touchscreen, Tracing, WebWorker,
} from 'puppeteer-core/lib/esm/puppeteer/puppeteer-core-browser.js';

// Public prototype identity survives bundler renaming and implementation subclasses.
// Walk nearest-first so ElementHandle retains its more specific type than JSHandle.
const publicTypes = new Map(Object.entries({
  Accessibility, Browser, BrowserContext, CDPSession, ConsoleMessage, Coverage,
  Dialog, ElementHandle, FileChooser, Frame, HTTPRequest, HTTPResponse, JSHandle,
  Keyboard, Mouse, Page, SecurityDetails, Target, Touchscreen, Tracing, WebWorker,
}).map(([name, type]) => [type.prototype, name]));
function remoteClass(value) {
  for (let prototype = Object.getPrototypeOf(value); prototype; prototype = Object.getPrototypeOf(prototype)) {
    const name = publicTypes.get(prototype);
    if (name) return name;
  }
  return value.constructor?.name ?? 'Object';
}

const objects = new Map();
const identities = new WeakMap();
let nextObject = 0;
let nextCallback = 0;
const callbacks = new Map();
const decodedFunctions = new Map();
const emit = (kind, value) => __quickjsEmit(kind, value);
const errorData = error => ({name: error?.name ?? 'Error', message: error?.message ?? String(error), stack: error?.stack ?? ''});
function encode(value) {
  if (value === undefined) return {$quickjs: 'undefined'};
  if (typeof value === 'bigint') return {$quickjs: 'bigint', value: String(value)};
  if (typeof value === 'number' && !Number.isFinite(value)) return {$quickjs: 'number', value: String(value)};
  if (Object.is(value, -0)) return {$quickjs: 'number', value: '-0'};
  if (value instanceof Uint8Array) return {$quickjs: 'bytes', value};
  if (Array.isArray(value)) return value.map(encode);
  if (value && typeof value === 'object') {
    if (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null) {
      let id = identities.get(value);
      if (!id) { id = ++nextObject; identities.set(value, id); }
      objects.set(id, value);
      return {$quickjs: 'object', id, class: remoteClass(value)};
    }
    return Object.fromEntries(Object.entries(value).map(([key, val]) => [key, encode(val)]));
  }
  return value;
}
function decode(value) {
  if (Array.isArray(value)) return value.map(decode);
  if (value && typeof value === 'object') {
    if (value.$quickjs === 'object') {
      if (!objects.has(value.id)) throw new Error(`Unknown remote object ${value.id}`);
      return objects.get(value.id);
    }
    if (value.$quickjs === 'function') {
      const key = `function:${value.id}`;
      if (!decodedFunctions.has(key)) decodedFunctions.set(key, (0, eval)(`(${value.source})`));
      return decodedFunctions.get(key);
    }
    if (value.$quickjs === 'callback') {
      const key = `callback:${value.id}`;
      if (decodedFunctions.has(key)) return decodedFunctions.get(key);
      const fn = (...args) => new Promise((resolve, reject) => {
      const id = ++nextCallback;
      callbacks.set(id, {resolve, reject});
      emit('callback', {id, callback: value.id, args: args.map(encode)});
      });
      decodedFunctions.set(key, fn);
      return fn;
    }
    if (value.$quickjs === 'undefined') return undefined;
    if (value.$quickjs === 'bigint') return BigInt(value.value);
    return Object.fromEntries(Object.entries(value).map(([key, val]) => [key, decode(val)]));
  }
  return value;
}
const transport = {
  send: message => __quickjsEmit('send', message),
  close: () => __quickjsEmit('close', ''),
};
async function call(request) {
  if (request.method === 'connect' && request.object === 0) {
    return puppeteer.connect({...decode(request.args[0] || {}), transport});
  }
  const object = objects.get(request.object);
  if (!object) throw new Error(`Unknown remote object ${request.object}`);
  if (request.operation === 'get') return object[request.method];
  const fn = object[request.method];
  if (typeof fn !== 'function') throw new Error(`Not a method: ${request.method}`);
  return Reflect.apply(fn, object, request.args.map(decode));
}
globalThis.__quickjsDispatch = (kind, payload) => {
  if (kind === 'message') { transport.onmessage?.(payload); return; }
  if (kind === 'closed') { transport.onclose?.(); return; }
  if (kind === 'timer') { fireTimer(Number(payload)); return; }
  const request = payload;
  if (kind === 'release') { objects.delete(request.id); return; }
  if (kind === 'callbackResult') {
    const pending = callbacks.get(request.id);
    if (!pending) return;
    callbacks.delete(request.id);
    if (request.error) pending.reject(Object.assign(new Error(request.error.message), {name: request.error.name}));
    else pending.resolve(decode(request.value));
    return;
  }
  call(request).then(
    value => emit('result', {id: request.id, value: encode(value)}),
    error => emit('result', {id: request.id, error: errorData(error)}),
  ).catch(error => emit('fatal', errorData(error)));
};
