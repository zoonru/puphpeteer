import {stringToTypedArray} from 'puppeteer-core/lib/puppeteer/util/encoding.js';
import {environment} from 'puppeteer-core/lib/puppeteer/environment.js';

// Files belong to the PHP host, including when Chrome runs remotely.
export function installFilesystem(request) {
  const call = (operation, ...args) => {
    if (!environment.value.followSymlinks) {
      return Promise.reject(new Error('followSymlinks=false is not supported by the PHP filesystem adapter'));
    }
    return request(operation, args);
  };
  const openFile = async (operation, ...args) => {
    const id = await call(operation, ...args);
    let closed = false;
    return {
      async writeFile(data) {
        if (closed) throw new Error('File handle is closed');
        await call('append', id, data);
      },
      async close() {
        if (closed) return;
        closed = true;
        await request('close', [id]);
      },
    };
  };
  Object.assign(environment.value, {
    async readFile(path, encoding) {
      const bytes = stringToTypedArray(await call('read', path), true);
      if (encoding === undefined) return bytes;
      if (encoding === 'ascii') return Array.from(bytes, byte => String.fromCharCode(byte & 0x7f)).join('');
      if (encoding !== 'utf8') throw new TypeError(`Unsupported file encoding: ${encoding}`);
      return new TextDecoder().decode(bytes);
    },
    async writeFile(path, data) { await call('write', path, data); },
    async mkdir(path, options = {}) { await call('mkdir', path, options.recursive ?? false); },
    openFileForWriting: path => openFile('open', path),
  });
  return (path, overwrite) => openFile('openRecording', path, overwrite);
}
