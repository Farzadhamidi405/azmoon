import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import formatDate from '../../lib/formatDate.js';
import ExamFormModal from '../../components/exams/ExamFormModal.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { canChangeExamStatus } from '../../lib/permissions.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  running: { label: 'فعال', tone: 'on' },
  published: { label: 'منتشرشده', tone: 'pending' },
  draft: { label: 'Draft', tone: 'off' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  archived: { label: 'آرشیو', tone: 'off' },
};

export default function ExamsPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const canChange = canChangeExamStatus(user);

  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, update, changeStatus } = useExams({
    page,
    per_page: 20,
  });

  const [editingExam, setEditingExam] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // وضعیت و هندلر پایان دستی آزمون (running -> completed)
  const [completingExam, setCompletingExam] = useState(null);
  const [completingBusy, setCompletingBusy] = useState(false);

  const handleConfirmComplete = async () => {
    if (!completingExam) return;
    setCompletingBusy(true);
    try {
      await changeStatus(completingExam.id, 'completed');
      notifySuccess(`آزمون «${completingExam.title}» با موفقیت به پایان رسید.`);
      setCompletingExam(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت پایان آزمون.';
      notifyError(msg);
    } finally {
      setCompletingBusy(false);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'title',
        title: 'عنوان آزمون',
        render: (_, row) => (
          <div>
            <div className="font-bold text-navy">{row.title}</div>
            <div className="text-xs text-ink-soft">
              {row.subject?.name ? `${row.subject.name} ` : ''}
              {row.description ? `· ${row.description}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'questions_count',
        title: 'سوالات',
        render: (_, row) => (
          <span className="font-mono text-xs">
            {row.questions_count ?? row.snapshot_questions_count ?? row.total_questions ?? '—'}
          </span>
        ),
      },
      {
        key: 'duration_minutes',
        title: 'مدت',
        render: (mins) => <span className="text-xs">{mins} دقیقه</span>,
      },
      {
        key: 'created_at',
        title: 'تاریخ',
        render: (d) => <span className="text-xs text-ink-soft">{d ? formatDate(d) : '—'}</span>,
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (st) => {
          const cfg = STATUS_CONFIG[st] || { label: st, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        title: '',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1.5 flex-wrap">
            {/* دکمه پایان آزمون برای آزمون‌های در حال اجرا */}
            {canChange && row.status === 'running' && (
              <Button
                size="sm"
                variant="outline"
                className="text-red hover:bg-red/10 border-red/40"
                disabled={completingBusy}
                onClick={() => setCompletingExam(row)}
              >
                پایان آزمون
              </Button>
            )}

            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                setEditingExam(row);
                setFormOpen(true);
              }}
            >
              {row.status === 'draft' ? 'ویرایش' : 'مشاهده'}
            </Button>
          </div>
        ),
      },
    ],
    [canChange, completingBusy]
  );

  const handleSave = async (formData) => {
    if (!editingExam) return;
    setBusy(true);
    try {
      await update(editingExam.id, formData);
      notifySuccess('آزمون با موفقیت به‌روزرسانی شد.');
      setEditingExam(null);
      setFormOpen(false);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ویرایش آزمون.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="آزمون‌ها"
        crumb={['پوهنتون کندز', 'آزمون‌ها']}
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <Button variant="outline" onClick={() => navigate('/admin/questions')}>
              بانک سوالات
            </Button>
            <Button variant="gold" onClick={() => navigate('/admin/exams/create')}>
              + ایجاد آزمون جدید
            </Button>
          </div>
        }
      />

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="هیچ آزمونی یافت نشد."
        />
        {pagination && pagination.lastPage > 1 && (
          <div className="mt-4">
            <Pagination
              currentPage={pagination.currentPage}
              lastPage={pagination.lastPage}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

      {editingExam && (
        <ExamFormModal
          open={formOpen}
          onClose={() => {
            setFormOpen(false);
            setEditingExam(null);
          }}
          onSave={handleSave}
          exam={editingExam}
          busy={busy}
        />
      )}

      {/* دیالوگ تأیید پایان دستی آزمون */}
      <ConfirmDialog
        open={!!completingExam}
        onClose={() => !completingBusy && setCompletingExam(null)}
        onConfirm={handleConfirmComplete}
        title="پایان دادن به آزمون"
        message={`آیا از پایان دادن به آزمون «${completingExam?.title}» اطمینان دارید؟ با این اقدام، وضعیت آزمون به «تکمیل‌شده» تغییر کرده و اعلان پایان آزمون برای سایر مدیران سیستم ارسال خواهد شد.`}
        confirmTone="danger"
        confirmLabel="پایان آزمون"
        busy={completingBusy}
      />
    </>
  );
}
