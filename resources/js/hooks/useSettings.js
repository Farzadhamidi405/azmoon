import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList } from '../lib/api.js';

export default function useSettings() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);

  const fetchSettings = useCallback(async (signal) => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/settings`, { signal });
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
    fetchSettings(controller.signal);
    return () => controller.abort();
  }, [fetchSettings]);

  const updateSetting = async (key, value) => {
    const res = await api.put(`${API_BASE}/admin/settings/${key}`, { value });
    fetchSettings();
    return res.data;
  };

  return {
    items,
    loading,
    reload: () => fetchSettings(),
    updateSetting,
  };
}
