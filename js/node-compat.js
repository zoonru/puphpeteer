// Minimal, read-only Node compatibility for packages loaded from an application root.
// The host must enforce the same root after resolving symlinks.
const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

function fromBase64(value) {
  if (typeof value !== 'string' || value.length % 4 !== 0) throw new TypeError('Invalid base64 data from host');
  const padding = value.endsWith('==') ? 2 : value.endsWith('=') ? 1 : 0;
  // Do not use a repeated-group regexp here: asset ZIPs produce multi-MB strings
  // and regexp backtracking can exhaust QuickJS's stack.
  for (let i = 0; i < value.length - padding; i++) {
    const code = value.charCodeAt(i);
    if (!((code >= 65 && code <= 90) || (code >= 97 && code <= 122) ||
      (code >= 48 && code <= 57) || code === 43 || code === 47)) {
      throw new TypeError('Invalid base64 data from host');
    }
  }
  for (let i = value.length - padding; i < value.length; i++) {
    if (value[i] !== '=') throw new TypeError('Invalid base64 data from host');
  }
  const result = new Uint8Array(value.length * 3 / 4 - padding);
  let position = 0;
  if (typeof atob === 'function') {
    // Keep the temporary binary string small: QuickJS's native decoder is much
    // faster than decoding multi-MB ZIP payloads one JavaScript character at a time.
    for (let offset = 0; offset < value.length; offset += 32768) {
      const chunk = atob(value.slice(offset, offset + 32768));
      for (let i = 0; i < chunk.length; i++) result[position++] = chunk.charCodeAt(i);
    }
    return result;
  }
  for (let i = 0; i < value.length; i += 4) {
    const n = (alphabet.indexOf(value[i]) << 18) | (alphabet.indexOf(value[i + 1]) << 12) |
      ((value[i + 2] === '=' ? 0 : alphabet.indexOf(value[i + 2])) << 6) |
      (value[i + 3] === '=' ? 0 : alphabet.indexOf(value[i + 3]));
    result[position++] = n >>> 16;
    if (position < result.length) result[position++] = n >>> 8;
    if (position < result.length) result[position++] = n;
  }
  return result;
}

function toBase64(bytes) {
  if (typeof btoa === 'function') {
    let result = '';
    // 24576 is divisible by three, so only the final chunk can need padding.
    for (let offset = 0; offset < bytes.length; offset += 24576) {
      result += btoa(String.fromCharCode.apply(null, bytes.subarray(offset, offset + 24576)));
    }
    return result;
  }
  let result = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const n = (bytes[i] << 16) | ((bytes[i + 1] || 0) << 8) | (bytes[i + 2] || 0);
    result += alphabet[n >>> 18] + alphabet[(n >>> 12) & 63] +
      (i + 1 < bytes.length ? alphabet[(n >>> 6) & 63] : '=') +
      (i + 2 < bytes.length ? alphabet[n & 63] : '=');
  }
  return result;
}

function utf8Encode(value) {
  if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(value);
  const encoded = unescape(encodeURIComponent(value));
  return Uint8Array.from(encoded, char => char.charCodeAt(0));
}

let decodeUtf8Host;

export class CompatBuffer extends Uint8Array {
  static from(value, encoding = 'utf8') {
    if (typeof value === 'string') {
      if (encoding === 'base64') return new CompatBuffer(fromBase64(value));
      if (encoding === 'hex') {
        if (value.length % 2 || !/^[0-9a-f]*$/i.test(value)) throw new TypeError('Invalid hex data');
        return new CompatBuffer(value.match(/../g)?.map(byte => parseInt(byte, 16)) || []);
      }
      if (encoding !== 'utf8' && encoding !== 'utf-8') throw new TypeError(`Unsupported encoding: ${encoding}`);
      return new CompatBuffer(utf8Encode(value));
    }
    if (value instanceof ArrayBuffer) return new CompatBuffer(value);
    return new CompatBuffer(value);
  }

  static alloc(size, fill = 0) {
    const result = new CompatBuffer(size);
    if (fill) result.fill(fill);
    return result;
  }

  static isBuffer(value) { return value instanceof CompatBuffer; }

  static concat(list, totalLength = list.reduce((size, item) => size + item.length, 0)) {
    const result = CompatBuffer.alloc(totalLength);
    let offset = 0;
    for (const item of list) { result.set(item.subarray(0, totalLength - offset), offset); offset += item.length; }
    return result;
  }

  toString(encoding = 'utf8', start = 0, end = this.length) {
    const bytes = this.subarray(start, end);
    if (encoding === 'base64') return toBase64(bytes);
    if (encoding === 'hex') return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    if (encoding === 'latin1' || encoding === 'binary') return Array.from(bytes, byte => String.fromCharCode(byte)).join('');
    if (encoding !== 'utf8' && encoding !== 'utf-8') throw new TypeError(`Unsupported encoding: ${encoding}`);
    return decodeUtf8Host(bytes);
  }

  slice(start, end) {
    const view = this.subarray(start, end);
    return new CompatBuffer(view.buffer, view.byteOffset, view.byteLength);
  }
  copy(target, targetStart = 0, sourceStart = 0, sourceEnd = this.length) {
    const data = this.subarray(sourceStart, sourceEnd).slice();
    const count = Math.min(data.length, target.length - targetStart);
    target.set(data.subarray(0, count), targetStart);
    return count;
  }
  readUInt16LE(offset = 0) { return new DataView(this.buffer, this.byteOffset, this.byteLength).getUint16(offset, true); }
  readUInt32LE(offset = 0) { return new DataView(this.buffer, this.byteOffset, this.byteLength).getUint32(offset, true); }
  readBigUInt64LE(offset = 0) { return new DataView(this.buffer, this.byteOffset, this.byteLength).getBigUint64(offset, true); }
  readUInt64LE(offset = 0) { return Number(this.readBigUInt64LE(offset)); }
  writeUInt16LE(value, offset = 0) { new DataView(this.buffer, this.byteOffset, this.byteLength).setUint16(offset, value, true); return offset + 2; }
  writeUInt32LE(value, offset = 0) { new DataView(this.buffer, this.byteOffset, this.byteLength).setUint32(offset, value, true); return offset + 4; }
  writeBigUInt64LE(value, offset = 0) { new DataView(this.buffer, this.byteOffset, this.byteLength).setBigUint64(offset, BigInt(value), true); return offset + 8; }
  write(value, offset = 0, length, encoding = 'utf8') {
    const data = CompatBuffer.from(value, encoding);
    const count = Math.min(length ?? data.length, data.length, this.length - offset);
    this.set(data.subarray(0, count), offset);
    return count;
  }
}

function normalize(path) {
  if (typeof path !== 'string') throw new TypeError('Path must be a string');
  const absolute = path.startsWith('/');
  const trailing = path.endsWith('/');
  const parts = [];
  for (const part of path.split('/')) {
    if (!part || part === '.') continue;
    if (part === '..' && parts.length && parts.at(-1) !== '..') parts.pop();
    else if (part === '..' && !absolute) parts.push(part);
    else if (part !== '..') parts.push(part);
  }
  let result = (absolute ? '/' : '') + parts.join('/');
  if (!result) result = '.';
  if (trailing && result !== '/' && result !== '.') result += '/';
  return result;
}

function createPath(root) {
  const resolve = (...segments) => {
    let path = '';
    for (let i = segments.length - 1; i >= -1; i--) {
      const segment = i < 0 ? root : segments[i];
      path = path ? `${segment}/${path}` : segment;
      if (segment.startsWith('/')) break;
    }
    return normalize(path);
  };
  const join = (...segments) => normalize(segments.filter(Boolean).join('/'));
  const dirname = path => { const value = normalize(path).replace(/\/$/, ''); const index = value.lastIndexOf('/'); return index < 0 ? '.' : index === 0 ? '/' : value.slice(0, index); };
  const basename = (path, suffix) => { let name = normalize(path).replace(/\/$/, '').split('/').at(-1); if (suffix && name.endsWith(suffix)) name = name.slice(0, -suffix.length); return name; };
  const extname = path => { const name = basename(path); const index = name.lastIndexOf('.'); return index <= 0 ? '' : name.slice(index); };
  const relative = (from, to) => {
    const left = resolve(from).split('/').filter(Boolean);
    const right = resolve(to).split('/').filter(Boolean);
    while (left.length && right.length && left[0] === right[0]) { left.shift(); right.shift(); }
    return [...left.map(() => '..'), ...right].join('/');
  };
  const posix = {sep: '/', delimiter: ':', normalize, resolve, join, dirname, basename, extname, relative, isAbsolute: path => path.startsWith('/')};
  return {...posix, posix, win32: {...posix, sep: '\\', basename: path => basename(path.replace(/\\/g, '/')), normalize: path => normalize(path.replace(/\\/g, '/')).replace(/\//g, '\\')}};
}

export function createNodeCompat({readBinary, exists, root, inflateRaw, deflateRaw, decodeUtf8, crc32}) {
  if (typeof readBinary !== 'function' || typeof exists !== 'function' || typeof decodeUtf8 !== 'function' || typeof crc32 !== 'function' || typeof root !== 'string' || !root.startsWith('/')) {
    throw new TypeError('Node compatibility requires readBinary, exists, decodeUtf8, crc32 and an absolute root');
  }
  decodeUtf8Host = decodeUtf8;
  const buffer = value => {
    if (!ArrayBuffer.isView(value) || value.BYTES_PER_ELEMENT !== 1) throw new TypeError('Invalid binary result from host');
    return new CompatBuffer(value.buffer, value.byteOffset, value.byteLength);
  };
  const path = createPath(root);
  const base = path.resolve(root);
  const scoped = file => {
    const resolved = path.resolve(file);
    if (resolved !== base && !resolved.startsWith(`${base}/`)) throw new Error(`Path outside plugin root: ${file}`);
    return resolved;
  };
  const fs = Object.freeze({
    existsSync: file => Boolean(exists(scoped(file))),
    readFileSync(file, options) {
      const bytes = buffer(readBinary(scoped(file)));
      const encoding = typeof options === 'string' ? options : options?.encoding;
      return encoding ? bytes.toString(encoding) : bytes;
    },
  });
  const transform = (callback, name) => (input, options = {}) => {
    if (typeof callback !== 'function') throw new Error(`Unsupported Node API: zlib.${name}`);
    const result = callback(input instanceof Uint8Array ? input : CompatBuffer.from(input), options.maxOutputLength ?? null);
    const bytes = buffer(result);
    if (options.maxOutputLength != null && bytes.length > options.maxOutputLength) throw new Error('zlib output exceeds maxOutputLength');
    return bytes;
  };
  const checksum = (data, value = 0) => {
    if (!Number.isInteger(value) || value < 0 || value > 0xffffffff) throw new RangeError('Invalid CRC32 seed');
    if (typeof data === 'string') data = CompatBuffer.from(data);
    if (!ArrayBuffer.isView(data)) throw new TypeError('CRC32 data must be a string or ArrayBuffer view');
    return crc32(new Uint8Array(data.buffer, data.byteOffset, data.byteLength), value);
  };
  const zlib = Object.freeze({inflateRawSync: transform(inflateRaw, 'inflateRawSync'), deflateRawSync: transform(deflateRaw, 'deflateRawSync'), crc32: checksum});
  const tty = Object.freeze({isatty: () => false});
  const util = Object.freeze({
    deprecate: fn => fn,
    formatWithOptions(_options, ...values) { return values.map(value => typeof value === 'string' ? value : String(value)).join(' '); },
  });
  const crypto = Object.freeze({randomFillSync(buffer, offset = 0, size = buffer.byteLength - offset) {
    if (!globalThis.crypto?.getRandomValues) throw new Error('Unsupported Node API: crypto.randomFillSync');
    if (!(buffer instanceof ArrayBuffer) && !ArrayBuffer.isView(buffer)) throw new TypeError('Expected BufferSource');
    if (!Number.isInteger(offset) || !Number.isInteger(size) || offset < 0 || size < 0 || offset + size > buffer.byteLength) throw new RangeError('Invalid random fill range');
    const bytes = new Uint8Array(buffer.buffer ?? buffer, (buffer.byteOffset ?? 0) + offset, size);
    for (let index = 0; index < bytes.length; index += 65536) globalThis.crypto.getRandomValues(bytes.subarray(index, index + 65536));
    return buffer;
  }});
  const builtins = {fs, path, buffer: {Buffer: CompatBuffer}, zlib, crypto, tty, util};
  for (const name of Object.keys(builtins)) builtins[`node:${name}`] = builtins[name];
  return builtins;
}
