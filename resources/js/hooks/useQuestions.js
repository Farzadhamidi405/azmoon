import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useQuestions(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchQuestions = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/questions`, {
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
    fetchQuestions({}, controller.signal);
    return () => controller.abort();
  }, [fetchQuestions]);

  const reload = useCallback(() => fetchQuestions(), [fetchQuestions]);

  const create = async (data) => {
    const res = await api.post(`${API_BASE}/admin/questions`, data);
    reload();
    return unwrapItem(res.data);
  };

  const update = async (id, data) => {
    const res = await api.put(`${API_BASE}/admin/questions/${id}`, data);
    reload();
    return unwrapItem(res.data);
  };

  const changeStatus = async (id, status) => {
    const res = await api.patch(`${API_BASE}/admin/questions/${id}/status`, { status });
    reload();
    return unwrapItem(res.data);
  };

  const remove = async (id) => {
    await api.delete(`${API_BASE}/admin/questions/${id}`);
    reload();
  };

  const getVersions = async (id) => {
    const res = await api.get(`${API_BASE}/admin/questions/${id}/versions`);
    return unwrapList(res.data).items || [];
  };

  return {
    items,
    pagination,
    loading,
    error,
    reload,
    create,
    update,
    changeStatus,
    remove,
    getVersions,
  };
}
