// Host services only. Puppeteer's browser logic remains upstream JavaScript.
let nextTimer = 0;
const timers = new Map();
globalThis.setTimeout = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  timers.set(id, {fn: () => fn(...args), interval: null});
  php.emit('timer', JSON.stringify({id, milliseconds: Math.max(0, Number(milliseconds) || 0)}));
  return id;
};
globalThis.clearTimeout = id => {
  timers.delete(id);
  php.emit('clearTimer', String(id));
};
globalThis.setInterval = (fn, milliseconds = 0, ...args) => {
  const id = ++nextTimer;
  const interval = Math.max(1, Number(milliseconds) || 0);
  timers.set(id, {fn: () => fn(...args), interval});
  php.emit('timer', JSON.stringify({id, milliseconds: interval}));
  return id;
};
globalThis.clearInterval = globalThis.clearTimeout;
globalThis.performance = {now: () => php.now()};
globalThis.console = Object.fromEntries(['log', 'warn', 'error', 'debug', 'info'].map(level => [level, (...args) => php.emit('log', args.map(String).join(' '))]));
export function fireTimer(id) {
  const timer = timers.get(id);
  if (!timer) return;
  if (timer.interval === null) timers.delete(id);
  timer.fn();
  if (timer.interval !== null && timers.has(id)) {
    php.emit('timer', JSON.stringify({id, milliseconds: timer.interval}));
  }
}
