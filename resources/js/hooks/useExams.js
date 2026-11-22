import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useExams(filters = {}) {
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const fetchExams = useCallback(
    async (params = {}, signal) => {
      setLoading(true);
      setError(null);
      try {
        const queryParams = { ...filters, ...params };
        const res = await api.get(`${API_BASE}/admin/exams`, {
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
    fetchExams({}, controller.signal);
    return () => controller.abort();
  }, [fetchExams]);

  const reload = useCallback((params) => fetchExams(params), [fetchExams]);

  const create = async (data) => {
    const res = await api.post(`${API_BASE}/admin/exams`, data);
    const created = unwrapItem(res.data);
    if (created && created.id) {
      setItems((prev) => [created, ...prev.filter((e) => e.id !== created.id)]);
    }
    await fetchExams();
    return created;
  };

  const update = async (id, data) => {
    const res = await api.put(`${API_BASE}/admin/exams/${id}`, data);
    const updated = unwrapItem(res.data);
    if (updated && updated.id) {
      setItems((prev) => prev.map((e) => (e.id === updated.id ? updated : e)));
    }
    await fetchExams();
    return updated;
  };

  const changeStatus = async (id, status) => {
    const res = await api.patch(`${API_BASE}/admin/exams/${id}/status`, { status });
    const updated = unwrapItem(res.data);
    if (updated && updated.id) {
      setItems((prev) => prev.map((e) => (e.id === updated.id ? updated : e)));
    }
    await fetchExams();
    return updated;
  };

  const remove = async (id) => {
    await api.delete(`${API_BASE}/admin/exams/${id}`);
    setItems((prev) => prev.filter((e) => e.id !== id));
    await fetchExams();
  };

  const getQuestions = async (examId) => {
    const res = await api.get(`${API_BASE}/admin/exams/${examId}/questions`);
    return res.data || { data: [], count: 0, total_score: 0 };
  };

  const getAvailableQuestions = async (examId, params = {}) => {
    const res = await api.get(`${API_BASE}/admin/exams/${examId}/available-questions`, { params });
    return unwrapList(res.data);
  };

  const syncQuestions = async (examId, questionIds, scores = {}) => {
    const res = await api.post(`${API_BASE}/admin/exams/${examId}/questions`, {
      question_ids: questionIds,
      scores,
    });
    await fetchExams();
    return res.data;
  };

  const removeQuestion = async (examId, questionId) => {
    const res = await api.delete(`${API_BASE}/admin/exams/${examId}/questions/${questionId}`);
    await fetchExams();
    return res.data;
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
    getQuestions,
    getAvailableQuestions,
    syncQuestions,
    removeQuestion,
  };
}
