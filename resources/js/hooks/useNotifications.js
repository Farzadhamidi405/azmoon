import { useCallback, useEffect, useState } from 'react';
import api, { API_BASE, unwrapList, unwrapItem } from '../lib/api.js';

export default function useNotifications() {
  const [items, setItems] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(true);

  const fetchNotifications = useCallback(async (signal) => {
    setLoading(true);
    try {
      const [listRes, countRes] = await Promise.allSettled([
        api.get(`${API_BASE}/admin/notifications`, { signal }),
        api.get(`${API_BASE}/admin/notifications/unread-count`, { signal }),
      ]);
      if (listRes.status === 'fulfilled') {
        const unwrapped = unwrapList(listRes.value.data);
        setItems(unwrapped.items || []);
      }
      if (countRes.status === 'fulfilled') {
        setUnreadCount(countRes.value.data?.unread_count || 0);
      }
    } catch {
      // ignore
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchNotifications(controller.signal);
    return () => controller.abort();
  }, [fetchNotifications]);

  const markAsRead = async (id) => {
    await api.post(`${API_BASE}/admin/notifications/${id}/read`);
    fetchNotifications();
  };

  const markAllAsRead = async () => {
    await api.post(`${API_BASE}/admin/notifications/read-all`);
    fetchNotifications();
  };

  return {
    items,
    unreadCount,
    loading,
    reload: () => fetchNotifications(),
    markAsRead,
    markAllAsRead,
  };
}
