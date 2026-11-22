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

        // استخراج مطمئن لیست سوالات بر اساس ساختارهای مختلف API
        let list = [];
        let pag = null;
        if (res.data) {
          if (Array.isArray(res.data.data)) {
            list = res.data.data;
          } else if (res.data.data && Array.isArray(res.data.data.data)) {
            list = res.data.data.data;
            pag = {
              currentPage: res.data.data.current_page || 1,
              lastPage: res.data.data.last_page || 1,
              total: res.data.data.total || list.length,
              perPage: res.data.data.per_page || 20,
            };
          } else {
            const unwrapped = unwrapList(res.data);
            list = unwrapped.items || [];
            pag = unwrapped.pagination;
          }
        }

        setItems(list);
        if (pag) setPagination(pag);
        return { items: list, pagination: pag };
      } catch (err) {
        if (err.name !== 'CanceledError' && !api.isCancel?.(err)) {
          setError(err);
        }
        return { items: [], pagination: null };
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

  const reload = useCallback((params) => fetchQuestions(params), [fetchQuestions]);

  const create = async (data) => {
    const res = await api.post(`${API_BASE}/admin/questions`, data);
    const created = unwrapItem(res.data);
    if (created && created.id) {
      setItems((prev) => [created, ...prev.filter((q) => q.id !== created.id)]);
    }
    await fetchQuestions();
    return created;
  };

  const update = async (id, data) => {
    const res = await api.put(`${API_BASE}/admin/questions/${id}`, data);
    const updated = unwrapItem(res.data);
    if (updated && updated.id) {
      setItems((prev) => prev.map((q) => (q.id === updated.id ? updated : q)));
    }
    await fetchQuestions();
    return updated;
  };

  const changeStatus = async (id, status) => {
    const res = await api.patch(`${API_BASE}/admin/questions/${id}/status`, { status });
    const updated = unwrapItem(res.data);
    if (updated && updated.id) {
      setItems((prev) => prev.map((q) => (q.id === updated.id ? updated : q)));
    }
    await fetchQuestions();
    return updated;
  };

  const remove = async (id) => {
    await api.delete(`${API_BASE}/admin/questions/${id}`);
    setItems((prev) => prev.filter((q) => q.id !== id));
    await fetchQuestions();
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
