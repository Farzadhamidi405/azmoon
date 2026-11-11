import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useCandidates(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchCandidates = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/candidates`, {
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
    fetchCandidates({}, controller.signal);
    return () => controller.abort();
  }, [fetchCandidates]);

  const reload = useCallback(() => fetchCandidates(), [fetchCandidates]);

  const create = async (data) => {
    const res = await api.post(`${API_BASE}/admin/candidates`, data);
    reload();
    return unwrapItem(res.data);
  };

  const update = async (id, data) => {
    const res = await api.put(`${API_BASE}/admin/candidates/${id}`, data);
    reload();
    return unwrapItem(res.data);
  };

  const remove = async (id) => {
    await api.delete(`${API_BASE}/admin/candidates/${id}`);
    reload();
  };

  const bulkGenerate = async (data) => {
    const res = await api.post(`${API_BASE}/admin/candidates/bulk-generate`, data);
    reload();
    return res.data;
  };

  const checkIn = async (id) => {
    const res = await api.post(`${API_BASE}/admin/candidates/${id}/checkin`);
    reload();
    return unwrapItem(res.data);
  };

  const getEntryCard = async (id) => {
    const res = await api.get(`${API_BASE}/admin/candidates/${id}/entry-card`);
    return unwrapItem(res.data);
  };

  return {
    items,
    pagination,
    loading,
    error,
    reload,
    create,
    update,
    remove,
    bulkGenerate,
    checkIn,
    getEntryCard,
  };
}
