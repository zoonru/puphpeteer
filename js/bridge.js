let wake;
let failure;
let pending = false;

export function emit(kind, value) {
  quickjs.postMessage([kind, value]);
  pending = true;
  wake?.();
  wake = undefined;
}

export function fail(error) {
  failure = error;
  wake?.();
  wake = undefined;
}

export async function drain(active) {
  if (failure) throw failure;
  if (!pending && active()) {
    await new Promise(resolve => { wake = resolve; });
    if (failure) throw failure;
  }
  const ready = pending;
  pending = false;
  return ready;
}
