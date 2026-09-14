import {stringToTypedArray} from 'puppeteer-core/lib/puppeteer/util/encoding.js';

// Upstream closes CDP handles at EOF only. Also close on cancellation/errors,
// and request bounded chunks without speculative reads.
export function getReadableFromProtocolStream(client, handle) {
  let cancelled = false;
  let closing;
  const close = () => closing ??= client.send('IO.close', {handle});
  return new ReadableStream({
    async pull(controller) {
      try {
        const {data, base64Encoded, eof} = await client.send('IO.read', {handle, size: 65536});
        if (cancelled) return;
        if (eof) await close();
        if (cancelled) return;
        if (data.length) controller.enqueue(stringToTypedArray(data, base64Encoded ?? false));
        if (eof) controller.close();
      } catch (error) {
        if (!cancelled) controller.error(error);
        await close().catch(() => {});
      }
    },
    cancel() { cancelled = true; return close(); },
  }, {highWaterMark: 0});
}
