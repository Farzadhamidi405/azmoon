import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useUsers(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchUsers = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/users`, {
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
    fetchUsers({}, controller.signal);
    return () => controller.abort();
  }, [fetchUsers]);

  const reload = useCallback(() => fetchUsers(), [fetchUsers]);

  const create = async (data) => {
    const res = await api.post(`${API_BASE}/admin/users`, data);
    reload();
    return unwrapItem(res.data);
  };

  const update = async (id, data) => {
    const res = await api.put(`${API_BASE}/admin/users/${id}`, data);
    reload();
    return unwrapItem(res.data);
  };

  const remove = async (id) => {
    await api.delete(`${API_BASE}/admin/users/${id}`);
    reload();
  };

  const toggleActive = async (id) => {
    const res = await api.post(`${API_BASE}/admin/users/${id}/toggle-active`);
    reload();
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
    toggleActive,
  };
}
