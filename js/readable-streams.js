// A reference crosses the bridge; at most one bounded chunk crosses per read.
export class GuestReadableStreams {
  #entries = new Map();
  #identities = new WeakMap();
  #sequence = 0;

  encode(stream) {
    let id = this.#identities.get(stream);
    if (!this.#entries.has(id)) {
      id = ++this.#sequence;
      this.#identities.set(stream, id);
      this.#entries.set(id, {stream, reader: null, buffer: null, offset: 0, pending: false, closed: false});
    }
    return {$quickjs: 'stream', id};
  }

  async read(id) {
    const entry = this.#entries.get(id);
    if (!entry) throw new Error('Unknown readable stream');
    if (entry.pending) throw new Error('Another stream read is pending');
    entry.pending = true;
    try {
      entry.reader ??= entry.stream.getReader();
      if (entry.buffer === null) {
        const {value, done} = await entry.reader.read();
        if (entry.closed) return null;
        if (done) {
          this.#entries.delete(id);
          entry.closed = true;
          entry.reader.releaseLock();
          return null;
        }
        if (!(value instanceof Uint8Array)) throw new TypeError('Expected a stream of Uint8Array chunks');
        entry.buffer = value;
        entry.offset = 0;
      }
      const end = Math.min(entry.offset + 65536, entry.buffer.byteLength);
      const chunk = entry.buffer.subarray(entry.offset, end);
      entry.offset = end;
      if (end === entry.buffer.byteLength) entry.buffer = null;
      return chunk;
    } catch (error) {
      await this.cancel(id).catch(() => {});
      throw error;
    } finally { entry.pending = false; }
  }

  async cancel(id) {
    const entry = this.#entries.get(id);
    if (!entry) return;
    this.#entries.delete(id);
    entry.closed = true;
    entry.buffer = null;
    try {
      if (entry.reader) await entry.reader.cancel();
      else await entry.stream.cancel();
    } finally { entry.reader?.releaseLock(); }
  }

  close() {
    for (const id of this.#entries.keys()) this.cancel(id).catch(() => {});
  }
}
