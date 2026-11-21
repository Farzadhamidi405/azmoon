import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList } from '../lib/api.js';

export default function useSessions(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchSessions = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/sessions`, {
          params: queryParams,
          signal,
        });
        const unwrapped = unwrapList(res.data);
        setItems(unwrapped.items || []);
        setPagination(unwrapped.pagination);
      } catch (err) {
        if (err.name !== 'CanceledError' && !api.isCancel?.(err)) {
          setError(err);
        }
      } finally {
        setLoading(false);
      }
    },
    [JSON.stringify(filters)]
  );

  useEffect(() => {
    const controller = new AbortController();
    fetchSessions({}, controller.signal);
    return () => controller.abort();
  }, [fetchSessions]);

  const reload = useCallback(() => fetchSessions(), [fetchSessions]);

  const transferSession = async (attemptId, data) => {
    const res = await api.post(`${API_BASE}/admin/attempts/${attemptId}/session-transfers`, data);
    reload();
    return res.data;
  };

  const manualOverride = async (attemptId, data) => {
    const res = await api.post(`${API_BASE}/admin/attempts/${attemptId}/overrides`, data);
    reload();
    return res.data;
  };

  return {
    items,
    pagination,
    loading,
    error,
    reload,
    transferSession,
    manualOverride,
  };
}
