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
import QuestionFormModal from '../../components/questions/QuestionFormModal.jsx';
import QuestionVersionModal from '../../components/questions/QuestionVersionModal.jsx';
import useQuestions from '../../hooks/useQuestions.js';
import useSubjects from '../../hooks/useSubjects.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  draft: { label: 'پیش‌نویس (Draft)', tone: 'off' },
  under_review: { label: 'در حال بازبینی', tone: 'pending' },
  approved: { label: 'تأییدشده', tone: 'teal' },
  active: { label: 'فعال در بانک', tone: 'on' },
  inactive: { label: 'غیرفعال', tone: 'bad' },
  archived: { label: 'آرشیو', tone: 'off' },
};

const DIFFICULTY_LABELS = {
  easy: 'آسان',
  medium: 'متوسط',
  hard: 'دشوار',
};

export default function QuestionsPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('questions.create') || hasRole('Super Admin', 'Exam Administrator', 'Question Manager');
  const canApprove = hasRole('Super Admin', 'Exam Administrator', 'Reviewer');

  const { items: subjects } = useSubjects();
  const [filters, setFilters] = useState({ search: '', status: '', difficulty: '', subject_id: '' });
  const [page, setPage] = useState(1);
  const { items, pagination, loading, reload, create, update, changeStatus, remove, getVersions } = useQuestions({
    ...filters,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [editingQuestion, setEditingQuestion] = useState(null);
  const [formType, setFormType] = useState('single_choice');
  const [versionModalQuestion, setVersionModalQuestion] = useState(null);
  const [deletingQuestion, setDeletingQuestion] = useState(null);
  const [statusConfirm, setStatusConfirm] = useState(null); // { question, nextStatus, label }
  const [busy, setBusy] = useState(false);

  const columns = useMemo(
    () => [
      {
        key: 'question_text',
        title: 'متن سوال',
        render: (_, row) => (
          <div className="max-w-[420px]">
            <div className="font-semibold text-ink line-clamp-2">{row.question_text}</div>
            <div className="text-xs text-ink-soft mt-0.5">
              {row.subject?.name ? `${row.subject.name} · ` : ''}
              {row.topic ? `موضوع: ${row.topic}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'type',
        title: 'نوع / بخش',
        render: (_, row) => {
          const typeNames = {
            single_choice: 'چهارگزینه‌ای',
            multiple_choice: 'چندگزینه‌ای',
            true_false: 'صحیح/غلط',
            short_answer: 'پاسخ کوتاه',
          };
          return <span className="text-xs font-medium">{typeNames[row.question_type] || row.question_type}</span>;
        },
      },
      {
        key: 'difficulty',
        title: 'دشواری / نمره',
        render: (_, row) => (
          <span className="text-xs">
            {DIFFICULTY_LABELS[row.difficulty] || row.difficulty} ({row.points ?? 1} نمره)
          </span>
        ),
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: (status) => {
          const cfg = STATUS_CONFIG[status] || { label: status, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        title: 'عملیات',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1 flex-wrap">
            {canApprove && row.status === 'under_review' && (
              <>
                <Button
                  size="sm"
                  variant="teal"
                  onClick={() => setStatusConfirm({ question: row, nextStatus: 'approved', label: 'تأیید سوال' })}
                >
                  تأیید
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setStatusConfirm({ question: row, nextStatus: 'draft', label: 'رد و بازگشت به پیش‌نویس' })}
                >
                  رد
                </Button>
              </>
            )}

            {canManage && row.status === 'draft' && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setStatusConfirm({ question: row, nextStatus: 'under_review', label: 'ارسال برای بررسی' })}
              >
                ارسال بررسی
              </Button>
            )}

            <Button
              size="sm"
              variant="ghost"
              onClick={() => setVersionModalQuestion(row)}
            >
              نسخه‌ها
            </Button>

            {canManage && (
              <Button
                size="sm"
                variant="ghost"
                onClick={() => {
                  setEditingQuestion(row);
                  setFormType(row.question_type || 'single_choice');
                  setFormOpen(true);
                }}
              >
                ویرایش
              </Button>
            )}

            {canManage && (row.status === 'draft' || row.status === 'inactive') && (
              <Button
                size="sm"
                variant="ghost"
                className="text-red hover:bg-red-soft"
                onClick={() => setDeletingQuestion(row)}
              >
                حذف
              </Button>
            )}
          </div>
        ),
      },
    ],
    [canManage, canApprove]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingQuestion) {
        await update(editingQuestion.id, formData);
        notifySuccess('سوال با موفقیت به‌روزرسانی شد.');
      } else {
        await create(formData);
        notifySuccess('سوال جدید با موفقیت ذخیره شد.');
      }
      setEditingQuestion(null);
    } finally {
      setBusy(false);
    }
  };

  const handleStatusChange = async () => {
    if (!statusConfirm) return;
    setBusy(true);
    try {
      await changeStatus(statusConfirm.question.id, statusConfirm.nextStatus);
      notifySuccess(`وضعیت سوال تغییر یافت.`);
      setStatusConfirm(null);
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!deletingQuestion) return;
    setBusy(true);
    try {
      await remove(deletingQuestion.id);
      notifySuccess('سوال با موفقیت حذف شد.');
      setDeletingQuestion(null);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="بانک سوالات"
        crumb={['سوالات', 'همه سوالات']}
        actions={
          canManage && (
            <div className="flex gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setEditingQuestion(null);
                  setFormType('single_choice');
                  setFormOpen(true);
                }}
              >
                + سوال گرامر / واژگان
              </Button>
              <Button
                variant="gold"
                onClick={() => {
                  setEditingQuestion(null);
                  setFormType('single_choice');
                  setFormOpen(true);
                }}
              >
                + سوال لیسنینگ / ریدینگ
              </Button>
            </div>
          )
        }
      />

      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
          placeholder="جستجو در متن سوال یا تاپیک..."
        />
        <FilterSelect
          value={filters.subject_id}
          onChange={(e) => setFilters((prev) => ({ ...prev, subject_id: e.target.value }))}
        >
          <option value="">مضمون: همه</option>
          {subjects.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </FilterSelect>
        <FilterSelect
          value={filters.difficulty}
          onChange={(e) => setFilters((prev) => ({ ...prev, difficulty: e.target.value }))}
        >
          <option value="">سطح دشواری: همه</option>
          <option value="easy">آسان</option>
          <option value="medium">متوسط</option>
          <option value="hard">دشوار</option>
        </FilterSelect>
        <FilterSelect
          value={filters.status}
          onChange={(e) => setFilters((prev) => ({ ...prev, status: e.target.value }))}
        >
          <option value="">وضعیت: همه</option>
          {Object.entries(STATUS_CONFIG).map(([k, v]) => (
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
          emptyTitle="هیچ سوالی با این مشخصات یافت نشد."
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

      <QuestionFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditingQuestion(null);
        }}
        onSave={handleSave}
        question={editingQuestion}
        initialType={formType}
        busy={busy}
      />

      <QuestionVersionModal
        open={!!versionModalQuestion}
        onClose={() => setVersionModalQuestion(null)}
        question={versionModalQuestion}
        onGetVersions={getVersions}
      />

      <ConfirmDialog
        open={!!statusConfirm}
        onClose={() => setStatusConfirm(null)}
        onConfirm={handleStatusChange}
        title="تغییر وضعیت سوال"
        message={`آیا از انتقال سوال به وضعیت «${statusConfirm?.label}» اطمینان دارید؟`}
        confirmLabel="تأیید"
        busy={busy}
      />

      <ConfirmDialog
        open={!!deletingQuestion}
        onClose={() => setDeletingQuestion(null)}
        onConfirm={handleDelete}
        title="حذف سوال"
        message="آیا از حذف این سوال مطمئن هستید؟ این عملیات غیرقابل برگشت است."
        confirmTone="danger"
        confirmLabel="حذف سوال"
        busy={busy}
      />
    </>
  );
}
