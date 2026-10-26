export function emit(kind, value) {
  quickjs.postMessage([kind, value]);
}

export function fail(error) {
  quickjs.reportError(error);
}
