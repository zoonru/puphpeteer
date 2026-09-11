import {fireTimer} from './host-environment.js';
import puppeteer from 'puppeteer-core/lib/esm/puppeteer/puppeteer-core-browser.js';

const objects = new Map();
const identities = new WeakMap();
let nextObject = 0;
let nextCallback = 0;
const callbacks = new Map();
const emit = (kind, value) => php.emit(kind, JSON.stringify(value));
const errorData = error => ({name: error?.name ?? 'Error', message: error?.message ?? String(error), stack: error?.stack ?? ''});
function encode(value) {
  if (value === undefined) return {$quickjs: 'undefined'};
  if (typeof value === 'bigint') return {$quickjs: 'bigint', value: String(value)};
  if (typeof value === 'number' && !Number.isFinite(value)) return {$quickjs: 'number', value: String(value)};
  if (Object.is(value, -0)) return {$quickjs: 'number', value: '-0'};
  if (value instanceof Uint8Array) return {$quickjs: 'bytes', value: Array.from(value)};
  if (Array.isArray(value)) return value.map(encode);
  if (value && typeof value === 'object') {
    if (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null) {
      let id = identities.get(value);
      if (!id) { id = ++nextObject; identities.set(value, id); }
      objects.set(id, value);
      return {$quickjs: 'object', id, class: value.constructor?.name ?? 'Object'};
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
    if (value.$quickjs === 'function') return (0, eval)(`(${value.source})`);
    if (value.$quickjs === 'callback') return (...args) => new Promise((resolve, reject) => {
      const id = ++nextCallback;
      callbacks.set(id, {resolve, reject});
      emit('callback', {id, callback: value.id, args: args.map(encode)});
    });
    if (value.$quickjs === 'undefined') return undefined;
    if (value.$quickjs === 'bigint') return BigInt(value.value);
    return Object.fromEntries(Object.entries(value).map(([key, val]) => [key, decode(val)]));
  }
  return value;
}
const transport = {
  send: message => php.emit('send', message),
  close: () => php.emit('close', ''),
};
async function call(request) {
  if (request.method === 'connect' && request.object === 0) {
    return puppeteer.connect({transport, defaultViewport: null, protocolTimeout: 10000});
  }
  const object = objects.get(request.object);
  if (!object) throw new Error(`Unknown remote object ${request.object}`);
  const fn = object[request.method];
  if (typeof fn !== 'function') throw new Error(`Not a method: ${request.method}`);
  return Reflect.apply(fn, object, request.args.map(decode));
}
globalThis.__quickjsDispatch = (kind, payload) => {
  if (kind === 'message') { transport.onmessage?.(payload); return; }
  if (kind === 'closed') { transport.onclose?.(); return; }
  if (kind === 'timer') { fireTimer(Number(payload)); return; }
  const request = JSON.parse(payload);
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
