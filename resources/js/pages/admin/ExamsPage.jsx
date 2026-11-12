import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Badge,
  Button,
  DataTable,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import formatDate from '../../lib/formatDate.js';
import ExamFormModal from '../../components/exams/ExamFormModal.jsx';
import { notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  running: { label: 'فعال', tone: 'on' },
  published: { label: 'منتشرشده', tone: 'pending' },
  draft: { label: 'Draft', tone: 'off' },
  completed: { label: 'تکمیل‌شده', tone: 'on' },
  archived: { label: 'آرشیو', tone: 'off' },
};

export default function ExamsPage() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, update } = useExams({
    page,
    per_page: 20,
  });

  const [editingExam, setEditingExam] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [busy, setBusy] = useState(false);

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
          <div className="flex items-center justify-end gap-1">
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
    []
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
          <Button variant="gold" onClick={() => navigate('/admin/exams/create')}>
            + ایجاد آزمون جدید
          </Button>
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
    </>
  );
}
