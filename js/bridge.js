let wake;
let failure;
let failed = false;
let pending = false;

export function emit(kind, value) {
  quickjs.postMessage([kind, value]);
  pending = true;
  wake?.();
  wake = undefined;
}

export function fail(error) {
  if (failed) return;
  failed = true;
  failure = error;
  if (typeof quickjs.reportError === 'function') quickjs.reportError(error);
  else {
    try { emit('bridgeError', {name: error?.name ?? 'Error', message: error?.message ?? String(error), stack: error?.stack ?? ''}); }
    catch { /* The legacy drain still observes failure if the data queue is full. */ }
  }
  wake?.();
  wake = undefined;
}

export async function drain(active) {
  if (failed) throw failure;
  if (!pending && active()) {
    await new Promise(resolve => { wake = resolve; });
    if (failed) throw failure;
  }
  const ready = pending;
  pending = false;
  return ready;
}
