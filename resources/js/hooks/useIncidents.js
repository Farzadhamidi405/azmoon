import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useIncidents(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchIncidents = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/incidents`, {
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
    fetchIncidents({}, controller.signal);
    return () => controller.abort();
  }, [fetchIncidents]);

  const reload = useCallback(() => fetchIncidents(), [fetchIncidents]);

  const report = async (data) => {
    const res = await api.post(`${API_BASE}/admin/incidents`, data);
    reload();
    return unwrapItem(res.data);
  };

  const resolve = async (id, data) => {
    const res = await api.post(`${API_BASE}/admin/incidents/${id}/resolve`, data);
    reload();
    return unwrapItem(res.data);
  };

  return {
    items,
    pagination,
    loading,
    error,
    reload,
    report,
    resolve,
  };
}
