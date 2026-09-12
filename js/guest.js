import {fireTimer, clearTimers} from './host-environment.js';
import {PluginAdapter} from './plugins/adapter.js';
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
const remoteClasses = new WeakMap();
function remoteClass(value) {
  const cached = remoteClasses.get(value);
  if (cached !== undefined) return cached;
  let name;
  for (let prototype = Object.getPrototypeOf(value); prototype; prototype = Object.getPrototypeOf(prototype)) {
    name = publicTypes.get(prototype);
    if (name) break;
  }
  name ??= value.constructor?.name ?? 'Object';
  remoteClasses.set(value, name);
  return name;
}

const objects = new Map();
const identities = new WeakMap();
let nextObject = 0;
let nextCallback = 0;
const callbacks = new Map();
const decodedFunctions = new Map();
const pinnedCallbacks = new Set();
const eventCallbacks = new Map();
const eventListeners = new Map();
function dropEvent(entry) {
  const listeners = eventListeners.get(entry.objectId);
  if (!listeners?.delete(entry)) return;
  entry.object.off(entry.event, entry.wrapper);
  if (!listeners.size) eventListeners.delete(entry.objectId);
  const remaining = (eventCallbacks.get(entry.callbackId) ?? 1) - 1;
  if (remaining) eventCallbacks.set(entry.callbackId, remaining);
  else {
    eventCallbacks.delete(entry.callbackId);
    if (!pinnedCallbacks.has(entry.callbackId)) {
      decodedFunctions.delete(`callback:${entry.callbackId}`);
      emit('releaseCallback', entry.callbackId);
    }
  }
}
function clearEvents(objectId, event) {
  for (const entry of [...(eventListeners.get(objectId) ?? [])]) {
    if (event === undefined || entry.event === event) dropEvent(entry);
  }
}
const emit = (kind, value) => __quickjsEmit(kind, value);
const errorData = error => ({name: error?.name ?? 'Error', message: error?.message ?? String(error), stack: error?.stack ?? ''});
function encodeRecord(value, ancestors) {
  const entries = Object.entries(value);
  const record = {};
  for (const [key, item] of entries) {
    const encoded = encode(item, ancestors);
    // Assignment to __proto__ has legacy setter semantics on ordinary objects,
    // while Object.fromEntries creates an own data property.
    if (key === '__proto__') Object.defineProperty(record, key, {value: encoded, enumerable: true, configurable: true, writable: true});
    else record[key] = encoded;
  }
  return Object.hasOwn(record, '$quickjs') ? {$quickjs: 'record', value: record} : record;
}
function encode(value, ancestors) {
  if (value === undefined) return {$quickjs: 'undefined'};
  const type = typeof value;
  if (type === 'bigint') return {$quickjs: 'bigint', value: String(value)};
  if (type === 'number') {
    if (!Number.isFinite(value)) return {$quickjs: 'number', value: String(value)};
    if (Object.is(value, -0)) return {$quickjs: 'number', value: '-0'};
    return value;
  }
  if (type !== 'object' || value === null) return value;
  if (value instanceof Uint8Array) return {$quickjs: 'bytes', value};
  const prototype = Object.getPrototypeOf(value);
  if (Array.isArray(value) || prototype === Object.prototype || prototype === null) {
    ancestors ??= new Set();
    if (ancestors.has(value)) throw new TypeError('Cannot transfer cyclic data');
    ancestors.add(value);
    try {
      if (Array.isArray(value)) return value.map(item => encode(item, ancestors));
      return encodeRecord(value, ancestors);
    } finally { ancestors.delete(value); }
  }
  let id = identities.get(value);
  if (id === undefined) {
    id = ++nextObject;
    identities.set(value, id);
  }
  // A released object can be encoded again. Re-pin it only in that case.
  if (!objects.has(id)) objects.set(id, value);
  return {$quickjs: 'object', id, class: remoteClass(value)};
}
function decodeRecord(value, pin, temporary) {
  const entries = Object.entries(value);
  const record = {};
  for (const [key, item] of entries) {
    const decoded = decode(item, pin, temporary);
    if (key === '__proto__') Object.defineProperty(record, key, {value: decoded, enumerable: true, configurable: true, writable: true});
    else record[key] = decoded;
  }
  return record;
}
function decode(value, pin = true, temporary = null) {
  if (value === null || typeof value !== 'object') return value;
  if (Array.isArray(value)) return value.map(item => decode(item, pin, temporary));
  if (value.$quickjs === 'record') return decodeRecord(value.value, pin, temporary);
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
    if (pin) pinnedCallbacks.add(value.id);
    else temporary?.add(value.id);
    const key = `callback:${value.id}`;
    if (decodedFunctions.has(key)) return decodedFunctions.get(key);
    const fn = (...args) => new Promise((resolve, reject) => {
    const id = ++nextCallback;
    callbacks.set(id, {resolve, reject});
    emit('callback', {id, callback: value.id, args: args.map(item => encode(item))});
    });
    decodedFunctions.set(key, fn);
    return fn;
  }
  if (value.$quickjs === 'undefined') return undefined;
  if (value.$quickjs === 'bigint') return BigInt(value.value);
  return decodeRecord(value, pin, temporary);
}
const transport = {
  send: message => __quickjsEmit('send', message),
  close: () => __quickjsEmit('close', ''),
};
const plugins = new PluginAdapter();
async function call(request) {
  const method = request.method;
  if (method === 'preparePlugins' && request.object === 0) return plugins.prepare(...request.args.map(item => decode(item)));
  if (method !== 'close' && method !== 'disconnect') plugins.check();
  if (method === 'connect' && request.object === 0) {
    return plugins.connect(puppeteer, decode(request.args[0] || {}), transport);
  }
  const object = objects.get(request.object);
  if (!object) throw new Error(`Unknown remote object ${request.object}`);
  if (request.operation === 'get') return object[method];
  const fn = object[method];
  if (typeof fn !== 'function') throw new Error(`Not a method: ${request.method}`);
  const [event, handler] = request.args;
  if ((method === 'on' || method === 'once') && handler?.$quickjs === 'callback') {
    const callback = decode(handler, false);
    const entry = {objectId: request.object, object, event, callbackId: handler.id, wrapper: null};
    entry.wrapper = (...args) => {
      const result = callback(...args);
      if (method === 'once') dropEvent(entry);
      result.catch(error => emit('log', `PHP event callback failed: ${error.message}`));
    };
    object.on(event, entry.wrapper);
    if (!eventListeners.has(request.object)) eventListeners.set(request.object, new Set());
    eventListeners.get(request.object).add(entry);
    eventCallbacks.set(handler.id, (eventCallbacks.get(handler.id) ?? 0) + 1);
    return object;
  }
  if (method === 'off' && handler?.$quickjs === 'callback') {
    const entries = [...(eventListeners.get(request.object) ?? [])];
    const entry = entries.findLast(entry => entry.event === event && entry.callbackId === handler.id);
    if (entry) dropEvent(entry);
    else if (!eventCallbacks.has(handler.id) && !pinnedCallbacks.has(handler.id)) emit('releaseCallback', handler.id);
    return object;
  }
  if (method === 'removeAllListeners' || (method === 'off' && handler === undefined)) clearEvents(request.object, event);
  const persistent = method === 'exposeFunction' || method === 'on' || method === 'once';
  const temporary = new Set();
  try {
    const result = await Reflect.apply(fn, object, request.args.map(item => decode(item, persistent, temporary)));
    if (method === 'close') clearEvents(request.object);
    if (result instanceof Page) await plugins.page(result);
    return result;
  } finally {
    for (const callbackId of temporary) {
      if (!pinnedCallbacks.has(callbackId) && !eventCallbacks.has(callbackId)) {
        decodedFunctions.delete(`callback:${callbackId}`);
        emit('releaseCallback', callbackId);
      }
    }
  }
}
globalThis.__quickjsDispatch = (kind, payload) => {
  if (kind === 'message') { transport.onmessage?.(payload); return; }
  if (kind === 'closed') {
    transport.onclose?.();
    for (const pending of callbacks.values()) pending.reject(new Error('PHP transport closed'));
    clearTimers();
    callbacks.clear();
    decodedFunctions.clear();
    pinnedCallbacks.clear();
    eventCallbacks.clear();
    eventListeners.clear();
    objects.clear();
    transport.onmessage = undefined;
    transport.onclose = undefined;
    return;
  }
  if (kind === 'timer') { fireTimer(Number(payload)); return; }
  const request = payload;
  if (kind === 'releaseFunction') { decodedFunctions.delete(`function:${request.id}`); return; }
  if (kind === 'release') { clearEvents(request.id); objects.delete(request.id); return; }
  if (kind === 'callbackResult') {
    const pending = callbacks.get(request.id);
    if (!pending) return;
    callbacks.delete(request.id);
    if (request.error) pending.reject(Object.assign(new Error(request.error.message), {name: request.error.name}));
    else pending.resolve(decode(request.value));
    return;
  }
  call(request).then(
    value => {
      try { emit('result', {id: request.id, value: encode(value)}); }
      catch (error) { emit('result', {id: request.id, error: errorData(error)}); }
    },
    error => emit('result', {id: request.id, error: errorData(error)}),
  ).catch(error => emit('fatal', errorData(error)));
};
