import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  PageHeader,
} from '../../components/ui/index.js';
import useBackups from '../../hooks/useBackups.js';
import formatDate from '../../lib/formatDate.js';
import { notifySuccess } from '../../lib/notify.js';

export default function BackupsPage() {
  const { items, loading, reload, createBackup, removeBackup, downloadBackup } = useBackups();
  const [creating, setCreating] = useState(false);
  const [downloadingId, setDownloadingId] = useState(null);
  const [downloadError, setDownloadError] = useState(null);
  const [deletingBackup, setDeletingBackup] = useState(null);
  const [busy, setBusy] = useState(false);

  const handleDownload = async (row) => {
    setDownloadingId(row.id);
    setDownloadError(null);
    try {
      await downloadBackup(row);
      notifySuccess(`دانلود فایل پشتیبان «${row.filename || row.id}» با موفقیت آغاز شد.`);
    } catch (err) {
      setDownloadError(err.message || 'خطا در دانلود فایل پشتیبان.');
    } finally {
      setDownloadingId(null);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'filename',
        title: 'نام فایل پشتیبان',
        render: (fn, row) => (
          <div>
            <div className="font-mono font-bold text-navy text-xs">{fn || row.name || `backup-${row.id}.sql.gz`}</div>
            <div className="text-[11px] text-ink-soft">نوع: دیتابیس کامل + ضمایم</div>
          </div>
        ),
      },
      {
        key: 'size',
        title: 'حجم فایل',
        render: (sz) => {
          if (!sz) return '—';
          const mb = (Number(sz) / (1024 * 1024)).toFixed(2);
          return <span className="font-mono text-xs">{mb} MB</span>;
        },
      },
      {
        key: 'created_at',
        title: 'زمان تهیه پشتیبان',
        render: (d) => <span className="font-mono text-xs text-ink-soft">{d ? formatDate(d) : '—'}</span>,
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (st, row) => {
          const status = st || row.status || 'completed';
          return (
            <Badge tone={status === 'completed' ? 'teal' : status === 'failed' ? 'bad' : 'pending'}>
              {status === 'completed' ? 'تکمیل‌شده' : status === 'failed' ? 'ناموفق' : 'در حال پردازش'}
            </Badge>
          );
        },
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1">
            <Button
              size="sm"
              variant="outline"
              loading={downloadingId === row.id}
              disabled={downloadingId === row.id || row.status === 'failed'}
              onClick={() => handleDownload(row)}
            >
              دانلود
            </Button>
            <Button
              size="sm"
              variant="ghost"
              className="text-red hover:bg-red-soft"
              disabled={downloadingId === row.id}
              onClick={() => setDeletingBackup(row)}
            >
              حذف
            </Button>
          </div>
        ),
      },
    ],
    [downloadingId]
  );

  const handleCreate = async () => {
    setCreating(true);
    setDownloadError(null);
    try {
      await createBackup();
      notifySuccess('فایل پشتیبان کامل سیستم با موفقیت ایجاد شد.');
    } finally {
      setCreating(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingBackup) return;
    setBusy(true);
    try {
      await removeBackup(deletingBackup.id);
      notifySuccess('فایل پشتیبان حذف شد.');
      setDeletingBackup(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="پشتیبان‌گیری و بازیابی داده‌ها (Backups)"
        crumb={['سیستم', 'پشتیبان‌گیری']}
        actions={
          <Button variant="gold" loading={creating} onClick={handleCreate}>
            + ایجاد نسخه پشتیبان فوری
          </Button>
        }
      />

      {/* Download Error Banner */}
      {downloadError && (
        <div className="card mb-4 p-4 border-red/30 bg-red-soft/20 text-xs text-red flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span>⚠️</span>
            <span>{downloadError}</span>
          </div>
          <Button size="sm" variant="ghost" onClick={() => setDownloadError(null)}>
            بستن
          </Button>
        </div>
      )}

      <div className="card mb-4 p-4 bg-panel border-line text-xs space-y-1">
        <div className="font-bold text-navy">امنیت داده‌ها در شرایط بحرانی (Disaster Recovery):</div>
        <div className="text-ink-soft leading-relaxed">
          نسخه‌های پشتیبان شامل کلیه سوالات، اسنپ‌شات‌های پاسخ متقاضیان، لاگ‌های حسابرسی و پیکربندی سیستم هستند. عملیات دانلود با اعتبارسنجی نشست امن مدیر ارشد انجام می‌گیرد.
        </div>
      </div>

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="تاکنون هیچ نسخه پشتیبانی ایجاد نشده است."
        />
      </div>

      <ConfirmDialog
        open={!!deletingBackup}
        onClose={() => setDeletingBackup(null)}
        onConfirm={handleDelete}
        title="حذف نسخه پشتیبان"
        message="آیا از حذف این فایل پشتیبان مطمئن هستید؟ این عملیات غیرقابل برگشت است."
        confirmTone="danger"
        confirmLabel="حذف پشتیبان"
        busy={busy}
      />
    </>
  );
}
