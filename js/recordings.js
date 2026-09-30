import {protocolReadableStream} from './readable-streams.js';

const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

export class Recordings {
  #entries = new WeakMap();

  async start(page, options = {}) {
    const settings = options;
    for (const name of ['maxWidth', 'maxHeight', 'frameRate', 'fps']) {
      if (settings[name] !== undefined && settings[name] <= 0) throw new Error(`\`${name}\` must be greater than 0.`);
    }
    const recording = page.createScreenRecording(settings);
    const client = page.mainFrame().client;
    const {stream: handle} = await client.send('Page.startScreenRecording', {
      audio: settings.audio,
      maxWidth: settings.maxWidth,
      maxHeight: settings.maxHeight,
      frameRate: settings.frameRate ?? settings.fps,
    });
    const entry = {client, handle, stream: null, stop: null, stopping: false, closed: false};
    this.#entries.set(recording, entry);
    recording.stop = () => this.stop(recording);
    entry.stream = protocolReadableStream({
      pull: async controller => {
        try {
          const chunk = await this.#read(entry);
          if (chunk) controller.enqueue(chunk); else controller.close();
        } catch (error) { await this.discard(recording).catch(() => {}); controller.error(error); }
      },
      cancel: () => this.discard(recording),
    });
    return recording;
  }

  readable(recording) {
    return this.#entry(recording).stream;
  }

  stop(recording) {
    const entry = this.#entry(recording);
    return entry.stop ??= (async () => {
      recording.stopped = true;
      try {
        await entry.client.send('Page.stopScreenRecording');
        entry.stopping = true;
      } catch (error) {
        await this.#close(entry);
        throw error;
      }
    })();
  }

  async discard(recording) {
    const entry = this.#entry(recording);
    try { await this.stop(recording); }
    finally { await this.#close(entry); }
  }

  async #read(entry) {
    while (!entry.closed) {
      try {
        const result = await entry.client.send('IO.read', {handle: entry.handle, size: 65536});
        if (result.eof && entry.stopping) await this.#close(entry);
        if (result.data) return {data: result.data, base64Encoded: result.base64Encoded ?? false};
        if (entry.closed) return null;
      } catch (error) {
        if (!error.message.includes('Read failed')) {
          await this.#close(entry);
          throw error;
        }
      }
      await wait(50);
    }
    return null;
  }

  async #close(entry) {
    if (entry.closed) return;
    entry.closed = true;
    await entry.client.send('IO.close', {handle: entry.handle}).catch(() => {});
  }

  #entry(recording) {
    const entry = this.#entries.get(recording);
    if (!entry) throw new Error('Unknown recording');
    return entry;
  }
}
