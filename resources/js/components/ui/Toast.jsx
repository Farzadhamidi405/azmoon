import { useCallback, useEffect, useState } from 'react';
import { subscribeToasts } from '../../lib/notify.js';

const MAX_TOASTS = 4;
const CLOSE_LABEL = '\u0628\u0633\u062A\u0646';

const STYLES = {
  error: 'border-red bg-red-soft text-red',
  success: 'border-teal bg-teal-soft text-teal',
  warning: 'border-amber bg-amber-soft text-amber',
  info: 'border-navy-3 bg-panel text-ink',
};

function ToastItem({ toast, onClose }) {
  useEffect(() => {
    const timer = setTimeout(() => onClose(toast.id), toast.duration);
    return () => clearTimeout(timer);
  }, [toast.id, toast.duration, onClose]);

  return (
    <div
      role={toast.type === 'error' ? 'alert' : 'status'}
      className={
        'pointer-events-auto flex items-start gap-3 rounded-ds border px-4 py-3 text-sm shadow-lg ' +
        (STYLES[toast.type] || STYLES.info)
      }
    >
      <span className="flex-1 leading-6">{toast.message}</span>
      <button
        type="button"
        onClick={() => onClose(toast.id)}
        aria-label={CLOSE_LABEL}
        className="min-h-8 min-w-8 shrink-0 rounded-ds text-lg leading-none opacity-70 hover:opacity-100"
      >
        {'\u00D7'}
      </button>
    </div>
  );
}

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);

  const close = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  useEffect(
    () =>
      subscribeToasts((toast) => {
        setToasts((prev) => [...prev, toast].slice(-MAX_TOASTS));
      }),
    [],
  );

  return (
    <>
      {children}
      <div
        aria-live="polite"
        className="pointer-events-none fixed inset-x-3 top-3 z-[100] flex flex-col gap-2 md:inset-x-auto md:end-4 md:top-auto md:bottom-4 md:w-96"
      >
        {toasts.map((toast) => (
          <ToastItem key={toast.id} toast={toast} onClose={close} />
        ))}
      </div>
    </>
  );
}

export default ToastProvider;
