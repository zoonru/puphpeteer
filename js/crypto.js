export function installCrypto(request) {
  if (typeof globalThis.crypto !== 'undefined') return;
  const integerArrays = [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array, Int32Array, Uint32Array, BigInt64Array, BigUint64Array];
  const unsupported = () => Promise.reject(new DOMException('Unsupported cryptographic operation', 'NotSupportedError'));
  const subtle = Object.fromEntries(['encrypt', 'decrypt', 'sign', 'verify', 'deriveBits', 'deriveKey', 'generateKey', 'importKey', 'exportKey', 'wrapKey', 'unwrapKey'].map(name => [name, unsupported]));
  subtle.digest = async (algorithm, data) => {
    const name = String(typeof algorithm === 'string' ? algorithm : algorithm?.name).toUpperCase();
    if (!['SHA-1', 'SHA-256', 'SHA-384', 'SHA-512'].includes(name)) throw new DOMException('Unsupported digest algorithm', 'NotSupportedError');
    if (!(data instanceof ArrayBuffer) && !ArrayBuffer.isView(data)) throw new TypeError('Expected BufferSource');
    const bytes = data instanceof ArrayBuffer ? new Uint8Array(data) : new Uint8Array(data.buffer, data.byteOffset, data.byteLength);
    const result = await request(name, bytes.slice());
    return result.subarray(1).slice().buffer;
  };
  globalThis.crypto = {
    getRandomValues(view) {
      if (!ArrayBuffer.isView(view) || !integerArrays.some(Type => view instanceof Type)) {
        throw new DOMException('Expected an integer typed array', 'TypeMismatchError');
      }
      if (view.byteLength > 65536) throw new DOMException('Random byte quota exceeded', 'QuotaExceededError');
      const bytes = php.randomBytes(view.byteLength);
      new Uint8Array(view.buffer, view.byteOffset, view.byteLength).set(bytes.subarray(1));
      return view;
    },
    randomUUID() {
      const bytes = this.getRandomValues(new Uint8Array(16));
      bytes[6] = (bytes[6] & 15) | 64;
      bytes[8] = (bytes[8] & 63) | 128;
      const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
      return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    },
    subtle,
  };
}
