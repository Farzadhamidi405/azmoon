import { useCallback, useEffect, useRef, useState } from 'react';
import { api, API_BASE, getErrorMessage, unwrapList } from '../lib/api.js';
import { notifyWarning } from '../lib/notify.js';
import { UI_TEXT } from '../components/ui/uiText.js';

// Real notifications only (GET/PATCH /api/v1/admin/notifications). No sample data, no real-time channel.
const BASE = `${API_BASE}/admin/notifications`;
const QUIET = { skipErrorToast: true };
const PAGE_SIZE = 10;
const REFRESH_MS = 60000;

function readCount(payload) {
  const data = payload && payload.data;
  const value = Number(data && data.unread_count);
  return Number.isFinite(value) && value >= 0 ? value : null;
}

// unreadCount stays null until the first successful answer and keeps its last value on failures,
// so the bell never shows a false zero. Background requests never raise a toast.
export default function useNotifications({ enabled = true } = {}) {
  const [unreadCount, setUnreadCount] = useState(null);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const aliveRef = useRef(true);
  const listSeq = useRef(0);
  const countSeq = useRef(0);

  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
    };
  }, []);

  const refreshCount = useCallback(async () => {
    if (!enabled) return;
    countSeq.current += 1;
    const seq = countSeq.current;
    try {
      const res = await api.get(`${BASE}/unread-count`, QUIET);
      if (!aliveRef.current || seq !== countSeq.current) return;
      const value = readCount(res.data);
      if (value !== null) setUnreadCount(value);
    } catch (err) {
      // Keep the previous value on purpose.
    }
  }, [enabled]);

  const loadList = useCallback(
    async (options = {}) => {
      if (!enabled) return;
      const silent = Boolean(options.silent);
      if (!silent) listSeq.current += 1;
      const seq = listSeq.current;
      if (!silent) {
        setLoading(true);
        setError(null);
      }
      try {
        const res = await api.get(BASE, { ...QUIET, params: { per_page: PAGE_SIZE } });
        if (!aliveRef.current || seq !== listSeq.current) return;
        setItems(unwrapList(res.data).items);
        setLoaded(true);
        setError(null);
      } catch (err) {
        if (!aliveRef.current || seq !== listSeq.current) return;
        setError(getErrorMessage(err, UI_TEXT.loadError));
      } finally {
        if (aliveRef.current && seq === listSeq.current && !silent) setLoading(false);
      }
    },
    [enabled],
  );

  const markRead = useCallback(
    async (item) => {
      if (!enabled || !item || item.id === undefined) return false;
      setBusy(true);
      try {
        await api.patch(`${BASE}/${item.id}/read`);
        if (!aliveRef.current) return true;
        setItems((prev) => prev.map((row) => (row.id === item.id ? { ...row, is_read: true } : row)));
        if (!item.is_read) setUnreadCount((prev) => (prev === null ? prev : Math.max(0, prev - 1)));
        refreshCount();
        return true;
      } catch (err) {
        if (aliveRef.current && err && (err.status === 404 || err.status === 409)) {
          if (err.status === 409) notifyWarning(getErrorMessage(err));
          loadList({ silent: true });
          refreshCount();
        }
        return false;
      } finally {
        if (aliveRef.current) setBusy(false);
      }
    },
    [enabled, loadList, refreshCount],
  );

  const markAllRead = useCallback(async () => {
    if (!enabled) return false;
    setBusy(true);
    try {
      await api.patch(`${BASE}/read-all`);
      if (!aliveRef.current) return true;
      await Promise.all([refreshCount(), loadList({ silent: true })]);
      return true;
    } catch (err) {
      return false;
    } finally {
      if (aliveRef.current) setBusy(false);
    }
  }, [enabled, loadList, refreshCount]);

  useEffect(() => {
    if (!enabled) return undefined;
    refreshCount();
    function onVisible() {
      if (document.visibilityState === 'visible') refreshCount();
    }
    const timer = window.setInterval(onVisible, REFRESH_MS);
    window.addEventListener('focus', refreshCount);
    document.addEventListener('visibilitychange', onVisible);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', refreshCount);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [enabled, refreshCount]);

  return { unreadCount, items, loading, loaded, error, busy, refreshCount, loadList, markRead, markAllRead };
}

export { useNotifications };
