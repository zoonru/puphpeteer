// URLSearchParams (core-js in QuickJS) owns storage, duplicate values and iteration.
// Examined Headers packages either require Node or silently drop invalid values.
function name(value) {
  const result = String(value);
  if (!/^[!#$%&'*+.^_`|~0-9a-z-]+$/i.test(result)) throw new TypeError('Invalid header name');
  return result.toLowerCase();
}
function value(input) {
  const result = String(input).replace(/^[\t ]+|[\t ]+$/g, '');
  if (/[\0\r\n\u0100-\uffff]/.test(result)) throw new TypeError('Invalid header value');
  return result;
}
export class Headers {
  #values = new URLSearchParams();
  constructor(init) {
    if (init == null) return;
    if (typeof init[Symbol.iterator] === 'function') {
      for (const entry of init) {
        if (entry == null || typeof entry === 'string' || typeof entry[Symbol.iterator] !== 'function') throw new TypeError('Expected a header pair');
        const pair = [...entry];
        if (pair.length !== 2) throw new TypeError('Expected a header pair');
        this.append(...pair);
      }
    } else {
      if (typeof init !== 'object') throw new TypeError('Expected HeadersInit');
      for (const [key, item] of Object.entries(init)) this.append(key, item);
    }
  }
  append(key, item) { this.#values.append(name(key), value(item)); }
  set(key, item) { this.#values.set(name(key), value(item)); }
  get(key) {
    const values = this.#values.getAll(name(key));
    return values.length ? values.join(', ') : null;
  }
  has(key) { return this.#values.has(name(key)); }
  delete(key) { this.#values.delete(name(key)); }
  getSetCookie() { return this.#values.getAll('set-cookie'); }
  *entries() {
    for (const key of new Set([...this.#values.keys()].sort())) {
      if (key === 'set-cookie') for (const item of this.getSetCookie()) yield [key, item];
      else yield [key, this.get(key)];
    }
  }
  *keys() { for (const [key] of this.entries()) yield key; }
  *values() { for (const [, item] of this.entries()) yield item; }
  forEach(callback, thisArg) { for (const [key, item] of this.entries()) callback.call(thisArg, item, key, this); }
  [Symbol.iterator]() { return this.entries(); }
  get [Symbol.toStringTag]() { return 'Headers'; }
}
