import {protocolReadableStream} from './readable-streams.js';

// Upstream closes CDP handles at EOF only. Also close on cancellation/errors,
// and request bounded chunks without speculative reads.
export function getReadableFromProtocolStream(client, handle) {
  let cancelled = false;
  let closing;
  const close = () => closing ??= client.send('IO.close', {handle});
  return protocolReadableStream({
    async pull(controller) {
      try {
        let result;
        do { result = await client.send('IO.read', {handle, size: 65536}); }
        while (!cancelled && !result.data.length && !result.eof);
        const {data, base64Encoded, eof} = result;
        if (cancelled) return;
        if (eof) await close();
        if (cancelled) return;
        if (data.length) controller.enqueue({data, base64Encoded: base64Encoded ?? false});
        if (eof) controller.close();
      } catch (error) {
        if (!cancelled) controller.error(error);
        await close().catch(() => {});
      }
    },
    cancel() { cancelled = true; return close(); },
  });
}
