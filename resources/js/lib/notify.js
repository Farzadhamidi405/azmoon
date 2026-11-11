// Tiny toast emitter (no dependencies). Used by lib/api.js and components/ui/Toast.jsx.
const listeners = new Set();
const recent = new Map();
const DEDUPE_MS = 2500;
let seq = 0;

export function subscribeToasts(listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function notify(type, message, options = {}) {
  if (!message || typeof message !== 'string') return null;
  const key = type + '|' + message;
  const now = Date.now();
  const last = recent.get(key);
  if (last && now - last < DEDUPE_MS) return null;
  if (recent.size > 50) recent.clear();
  recent.set(key, now);
  const toast = {
    id: ++seq,
    type,
    message,
    duration: options.duration ?? (type === 'error' ? 7000 : 4500),
  };
  listeners.forEach((listener) => listener(toast));
  return toast.id;
}

export const notifyError = (message, options) => notify('error', message, options);
export const notifySuccess = (message, options) => notify('success', message, options);
export const notifyWarning = (message, options) => notify('warning', message, options);
export const notifyInfo = (message, options) => notify('info', message, options);
