import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList } from '../lib/api.js';

export default function useBackups() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);

  const fetchBackups = useCallback(async (signal) => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/backups`, { signal });
      const unwrapped = unwrapList(res.data);
      setItems(unwrapped.items || []);
    } catch {
      // ignore
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchBackups(controller.signal);
    return () => controller.abort();
  }, [fetchBackups]);

  const createBackup = async () => {
    const res = await api.post(`${API_BASE}/admin/backups`);
    fetchBackups();
    return res.data;
  };

  const removeBackup = async (id) => {
    await api.delete(`${API_BASE}/admin/backups/${id}`);
    fetchBackups();
  };

  return {
    items,
    loading,
    reload: () => fetchBackups(),
    createBackup,
    removeBackup,
  };
}
