import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import ExamFormModal from '../../components/exams/ExamFormModal.jsx';
import useExams from '../../hooks/useExams.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifySuccess } from '../../lib/notify.js';

const STATUS_LABELS = {
  draft: { label: 'Draft (پیش‌نویس)', tone: 'off' },
  under_review: { label: 'در حال بازبینی', tone: 'pending' },
  approved: { label: 'تأییدشده', tone: 'teal' },
  scheduled: { label: 'زمان‌بندی‌شده', tone: 'pending' },
  published: { label: 'منتشرشده', tone: 'gold' },
  running: { label: 'در حال برگزاری', tone: 'on' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  reviewed: { label: 'بازبینی‌شده', tone: 'off' },
  archived: { label: 'آرشیو', tone: 'bad' },
};

const NEXT_STATUSES = {
  draft: [{ status: 'under_review', label: 'ارسال برای بررسی' }],
  under_review: [
    { status: 'draft', label: 'برگشت به پیش‌نویس' },
    { status: 'approved', label: 'تأیید آزمون' },
  ],
  approved: [
    { status: 'scheduled', label: 'زمان‌بندی' },
    { status: 'published', label: 'انتشار آزمون' },
  ],
  scheduled: [{ status: 'published', label: 'انتشار آزمون' }],
  published: [
    { status: 'running', label: 'شروع برگزاری' },
    { status: 'archived', label: 'آرشیو' },
  ],
  running: [{ status: 'completed', label: 'اتمام آزمون' }],
  completed: [
    { status: 'reviewed', label: 'تأیید نتایج' },
    { status: 'archived', label: 'آرشیو' },
  ],
  reviewed: [{ status: 'archived', label: 'آرشیو' }],
  archived: [],
};

export default function ExamsPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('exams.create') || hasRole('Super Admin', 'Exam Administrator');

  const [filters, setFilters] = useState({ search: '', status: '' });
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, create, update, changeStatus, remove } = useExams({
    ...filters,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [editingExam, setEditingExam] = useState(null);
  const [deletingExam, setDeletingExam] = useState(null);
  const [statusConfirm, setStatusConfirm] = useState(null); // { exam, nextStatus, label }
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
              {row.subject ? `${row.subject.name} (${row.subject.code})` : 'بدون مضمون'}
            </div>
          </div>
        ),
      },
      {
        key: 'duration_minutes',
        title: 'مدت زمان',
        render: (val) => `${val || 60} دقیقه`,
      },
      {
        key: 'marks',
        title: 'نمره کل / قبولی',
        render: (_, row) => (
          <span className="font-semibold">
            {row.pass_marks ?? 60} / {row.total_marks ?? 100}
          </span>
        ),
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (status) => {
          const cfg = STATUS_LABELS[status] || { label: status, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => {
          const transitions = NEXT_STATUSES[row.status] || [];
          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              {canManage && transitions.map((t) => (
                <Button
                  key={t.status}
                  size="sm"
                  variant={t.status === 'published' || t.status === 'running' ? 'gold' : 'outline'}
                  onClick={() => setStatusConfirm({ exam: row, nextStatus: t.status, label: t.label })}
                >
                  {t.label}
                </Button>
              ))}

              {canManage && (
                <>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setEditingExam(row);
                      setFormOpen(true);
                    }}
                  >
                    ویرایش
                  </Button>
                  {row.status === 'draft' && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-red hover:bg-red-soft"
                      onClick={() => setDeletingExam(row)}
                    >
                      حذف
                    </Button>
                  )}
                </>
              )}
            </div>
          );
        },
      },
    ],
    [canManage]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingExam) {
        await update(editingExam.id, formData);
        notifySuccess('آزمون با موفقیت به‌روزرسانی شد.');
      } else {
        await create(formData);
        notifySuccess('آزمون با موفقیت ایجاد شد.');
      }
      setEditingExam(null);
    } finally {
      setBusy(false);
    }
  };

  const handleStatusChange = async () => {
    if (!statusConfirm) return;
    setBusy(true);
    try {
      await changeStatus(statusConfirm.exam.id, statusConfirm.nextStatus);
      notifySuccess(`وضعیت آزمون به «${statusConfirm.label}» تغییر کرد.`);
      setStatusConfirm(null);
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingExam) return;
    setBusy(true);
    try {
      await remove(deletingExam.id);
      notifySuccess('آزمون با موفقیت حذف شد.');
      setDeletingExam(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="آزمون‌ها"
        crumb={['امتحانات', 'لیست آزمون‌ها']}
        actions={
          canManage && (
            <Button
              variant="gold"
              onClick={() => {
                setEditingExam(null);
                setFormOpen(true);
              }}
            >
              + ایجاد آزمون جدید
            </Button>
          )
        }
      />

      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
          placeholder="جستجو در عنوان آزمون..."
        />
        <FilterSelect
          value={filters.status}
          onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value }))}
        >
          <option value="">وضعیت: همه</option>
          {Object.entries(STATUS_LABELS).map(([k, v]) => (
            <option key={k} value={k}>
              {v.label}
            </option>
          ))}
        </FilterSelect>
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          data={items}
          loading={loading}
          emptyTitle="هیچ آزمونی یافت نشد."
        />
        {pagination && (
          <div className="mt-4">
            <Pagination
              currentPage={pagination.currentPage}
              lastPage={pagination.lastPage}
              onPageChange={setPage}
            />
          </div>
        )}
      </div>

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

      <ConfirmDialog
        open={!!statusConfirm}
        onClose={() => setStatusConfirm(null)}
        onConfirm={handleStatusChange}
        title="تغییر وضعیت آزمون"
        message={`آیا از تغییر وضعیت آزمون «${statusConfirm?.exam?.title}» به «${statusConfirm?.label}» اطمینان دارید؟`}
        confirmLabel="تأیید و تغییر"
        busy={busy}
      />

      <ConfirmDialog
        open={!!deletingExam}
        onClose={() => setDeletingExam(null)}
        onConfirm={handleDelete}
        title="حذف آزمون"
        message={`آیا از حذف آزمون «${deletingExam?.title}» مطمئن هستید؟`}
        confirmTone="danger"
        confirmLabel="حذف"
        busy={busy}
      />
    </>
  );
}
