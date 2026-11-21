import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList } from '../lib/api.js';

export default function useAuditLogs(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchLogs = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/audit-logs`, {
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
    fetchLogs({}, controller.signal);
    return () => controller.abort();
  }, [fetchLogs]);

  return {
    items,
    pagination,
    loading,
    error,
    reload: () => fetchLogs(),
  };
}
