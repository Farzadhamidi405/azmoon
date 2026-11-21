import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapItem } from '../lib/api.js';

export default function useResults(examId) {
  const [results, setResults] = useState([]);
  const [statistics, setStatistics] = useState(null);
  const [analytics, setAnalytics] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const fetchData = useCallback(
    async (signal) => {
      if (!examId) return;
      setLoading(true);
      setError(null);
      try {
        const [resResults, resStats, resAnalytics] = await Promise.allSettled([
          api.get(`${API_BASE}/admin/exams/${examId}/results`, { signal }),
          api.get(`${API_BASE}/admin/exams/${examId}/statistics`, { signal }),
          api.get(`${API_BASE}/admin/exams/${examId}/question-analytics`, { signal }),
        ]);

        if (resResults.status === 'fulfilled') {
          const raw = resResults.value.data;
          setResults(Array.isArray(raw?.data) ? raw.data : raw || []);
        }
        if (resStats.status === 'fulfilled') {
          setStatistics(unwrapItem(resStats.value.data));
        }
        if (resAnalytics.status === 'fulfilled') {
          const raw = resAnalytics.value.data;
          setAnalytics(Array.isArray(raw?.data) ? raw.data : raw || []);
        }
      } catch (err) {
        if (err.name !== 'CanceledError' && !api.isCancel?.(err)) {
          setError(err);
        }
      } finally {
        setLoading(false);
      }
    },
    [examId]
  );

  useEffect(() => {
    const controller = new AbortController();
    fetchData(controller.signal);
    return () => controller.abort();
  }, [fetchData]);

  const getAttemptResult = async (attemptId) => {
    const res = await api.get(`${API_BASE}/admin/attempts/${attemptId}/result`);
    return unwrapItem(res.data);
  };

  return {
    results,
    statistics,
    analytics,
    loading,
    error,
    reload: () => fetchData(),
    getAttemptResult,
  };
}
