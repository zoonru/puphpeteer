import {stringToTypedArray} from 'puppeteer-core/lib/puppeteer/util/encoding.js';

const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

export class Recordings {
  #entries = new WeakMap();
  constructor(openFile) { this.openFile = openFile; }

  async start(page, options = {}) {
    const {path, overwrite = true, ...settings} = options;
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
    const entry = {client, handle, file: null, controller: null, stream: null, pump: null, stop: null, stopping: false, closed: false};
    this.#entries.set(recording, entry);
    recording.stop = () => this.stop(recording);
    entry.stream = new ReadableStream({
      start: controller => { entry.controller = controller; },
      pull: path ? undefined : async controller => {
        try {
          const chunk = await this.#read(entry);
          if (chunk) controller.enqueue(chunk); else controller.close();
        } catch (error) { controller.error(error); }
      },
      cancel: () => this.discard(recording),
    }, {highWaterMark: path ? 1 : 0});
    try {
      if (path) {
        entry.file = await this.openFile(path, overwrite);
        entry.pump = this.#write(entry);
        entry.pump.catch(() => {});
      }
      return recording;
    } catch (error) {
      await this.discard(recording);
      throw error;
    }
  }

  readable(recording) {
    return this.#entry(recording).stream;
  }

  stop(recording) {
    const entry = this.#entry(recording);
    return entry.stop ??= (async () => {
      recording.stopped = true;
      await entry.client.send('Page.stopScreenRecording');
      entry.stopping = true;
      await entry.pump;
    })();
  }

  async discard(recording) {
    const entry = this.#entry(recording);
    try { await this.stop(recording); }
    finally { await this.#close(entry); }
  }

  async #write(entry) {
    try {
      while (true) {
        const chunk = await this.#read(entry);
        if (!chunk) break;
        await entry.file.writeFile(chunk);
        entry.controller.enqueue(chunk);
      }
      entry.controller.close();
    } catch (error) {
      await this.#close(entry);
      entry.controller.error(error);
      throw error;
    } finally { await entry.file.close(); }
  }

  async #read(entry) {
    while (!entry.closed) {
      try {
        const result = await entry.client.send('IO.read', {handle: entry.handle, size: 65536});
        if (result.eof && entry.stopping) await this.#close(entry);
        if (result.data) return stringToTypedArray(result.data, result.base64Encoded ?? false);
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
