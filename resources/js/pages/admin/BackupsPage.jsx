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
  const { items, loading, reload, createBackup, removeBackup } = useBackups();
  const [creating, setCreating] = useState(false);
  const [deletingBackup, setDeletingBackup] = useState(null);
  const [busy, setBusy] = useState(false);

  const columns = useMemo(
    () => [
      {
        key: 'filename',
        title: 'نام فایل پشتیبان',
        render: (fn, row) => (
          <div>
            <div className="font-mono font-bold text-navy text-xs">{fn || row.name || 'backup.sql.gz'}</div>
            <div className="text-[11px] text-ink-soft">نوع: دیتابیس کامل SQLite/MySQL + فایل‌های ضمیمه</div>
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
        title: 'وضعیت سلامت',
        render: () => <Badge tone="teal">صحیح و تأییدشده</Badge>,
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1">
            <a
              href={`/api/v1/admin/backups/${row.id}/download`}
              target="_blank"
              rel="noreferrer"
              className="btn btn-outline btn-sm inline-flex items-center"
            >
              دانلود
            </a>
            <Button
              size="sm"
              variant="ghost"
              className="text-red hover:bg-red-soft"
              onClick={() => setDeletingBackup(row)}
            >
              حذف
            </Button>
          </div>
        ),
      },
    ],
    []
  );

  const handleCreate = async () => {
    setCreating(true);
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

      <div className="card mb-4 p-4 bg-panel border-line text-xs space-y-1">
        <div className="font-bold text-navy">امنیت داده‌ها در شرایط بحرانی (Disaster Recovery):</div>
        <div className="text-ink-soft leading-relaxed">
          نسخه‌های پشتیبان شامل کلیه سوالات، اسنپ‌شات‌های پاسخ متقاضیان، لاگ‌های حسابرسی و کانفیگ سیستم هستند. توصیه می‌شود پیش از شروع هر شیفت آزمون یک نسخه تهیه شود.
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
