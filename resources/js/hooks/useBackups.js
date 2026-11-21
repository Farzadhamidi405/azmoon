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

  /**
   * Secure download utilizing project Sanctum session cookie authentication via Axios blob response.
   * Handles 401, 403, 404, 422 cleanly without exposing sensitive info in URL.
   */
  const downloadBackup = async (backup) => {
    const backupId = backup.id;
    try {
      const res = await api.get(`${API_BASE}/admin/backups/${backupId}/download`, {
        responseType: 'blob',
        skipErrorToast: true,
      });

      // Extract filename from header or fallback
      let filename = backup.filename || backup.name || `backup-${backupId}.sql.gz`;
      const disposition = res.headers?.['content-disposition'];
      if (disposition && disposition.includes('filename=')) {
        const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
        if (match && match[1]) {
          filename = match[1].replace(/['"]/g, '').trim();
        }
      }

      // Create download trigger
      const blob = new Blob([res.data], {
        type: res.headers?.['content-type'] || 'application/octet-stream',
      });
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', filename);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      window.URL.revokeObjectURL(url);
    } catch (err) {
      // If error payload is blob, parse JSON text
      let errorMsg = 'خطا در دانلود فایل پشتیبان.';
      if (err.response?.data instanceof Blob) {
        try {
          const text = await err.response.data.text();
          const json = JSON.parse(text);
          if (json.message) errorMsg = json.message;
        } catch {
          // fallback
        }
      }

      const status = err.response?.status;
      if (status === 401) {
        errorMsg = 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
      } else if (status === 403) {
        errorMsg = 'شما مجوز دانلود فایل پشتیبان را ندارید (فقط مدیر ارشد مجاز است).';
      } else if (status === 404) {
        errorMsg = 'فایل پشتیبان مورد نظر در سرور یافت نشد.';
      } else if (status === 422) {
        errorMsg = 'تنها فایل‌های پشتیبان کامل‌شده قابل دانلود هستند.';
      }

      const customErr = new Error(errorMsg);
      customErr.status = status;
      throw customErr;
    }
  };

  return {
    items,
    loading,
    reload: () => fetchBackups(),
    createBackup,
    removeBackup,
    downloadBackup,
  };
}
