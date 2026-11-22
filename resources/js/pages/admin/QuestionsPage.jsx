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
import { useAuth } from '../../context/AuthContext.jsx';
import { notifyError, notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  active: { label: 'Active', tone: 'on' },
  approved: { label: 'Approved', tone: 'teal' },
  under_review: { label: 'Under Review', tone: 'pending' },
  draft: { label: 'Draft', tone: 'off' },
  inactive: { label: 'Inactive', tone: 'bad' },
  archived: { label: 'Archived', tone: 'off' },
};

const TYPE_LABELS = {
  single: 'چهارگزینه‌ای (تک‌پاسخی)',
  multi: 'چندگزینه‌ای',
  true_false: 'صحیح / غلط',
  short_answer: 'پاسخ کوتاه',
  single_choice: 'چهارگزینه‌ای',
  multiple_choice: 'چندگزینه‌ای',
};

const SECTION_BADGES = [
  { key: '', label: 'همه' },
  { key: 'Grammar', label: 'Grammar' },
  { key: 'Vocabulary', label: 'Vocabulary' },
  { key: 'Reading', label: 'Reading' },
  { key: 'Listening', label: 'Listening' },
];

export default function QuestionsPage() {
  const { can, hasRole } = useAuth();
  const canManage = can('questions.create') || hasRole('Super Admin', 'Exam Administrator', 'Question Manager');

  const [selectedSection, setSelectedSection] = useState('');
  const [filters, setFilters] = useState({ search: '', status: '', difficulty: '', topic: '' });
  const [page, setPage] = useState(1);

  const { items, pagination, loading, reload, create, update, remove, getVersions } = useQuestions({
    ...filters,
    section_name: selectedSection || undefined,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [formSection, setFormSection] = useState('Grammar');
  const [editingQuestion, setEditingQuestion] = useState(null);
  const [deletingQuestion, setDeletingQuestion] = useState(null);
  const [versionModalQuestion, setVersionModalQuestion] = useState(null);
  const [busy, setBusy] = useState(false);

  const columns = useMemo(
    () => [
      {
        key: 'question_text',
        title: 'سوال',
        render: (_, row) => (
          <div className="max-w-[440px]">
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
        title: 'نوع',
        render: (_, row) => {
          const typeLabel = TYPE_LABELS[row.question_type] || row.question_type;
          return (
            <div>
              <div className="text-xs font-bold text-navy">{row.section_name || 'Grammar'}</div>
              <div className="text-[11px] text-ink-soft">{typeLabel}</div>
            </div>
          );
        },
      },
      {
        key: 'options_count',
        title: 'گزینه‌ها',
        render: (_, row) => {
          const count = Array.isArray(row.options) ? row.options.length : 0;
          return <span className="text-xs">{count > 0 ? `${count} گزینه` : '—'}</span>;
        },
      },
      {
        key: 'score',
        title: 'نمره',
        render: (_, row) => (
          <span className="font-mono text-xs font-bold text-navy">
            {row.score ?? row.points ?? 2}
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
        title: '',
        align: 'end',
        render: (_, row) => (
          <div className="flex items-center justify-end gap-1 flex-wrap">
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
                  setFormSection(row.section_name || 'Grammar');
                  setFormOpen(true);
                }}
              >
                ویرایش
              </Button>
            )}
            {/* دکمه حذف فقط برای سوالات با وضعیت draft */}
            {canManage && row.status === 'draft' && (
              <Button
                size="sm"
                variant="ghost"
                className="text-red hover:bg-red/10"
                onClick={() => setDeletingQuestion(row)}
              >
                حذف
              </Button>
            )}
          </div>
        ),
      },
    ],
    [canManage]
  );

  const handleSave = async (formData) => {
    setBusy(true);
    try {
      if (editingQuestion) {
        await update(editingQuestion.id, formData);
        notifySuccess('سوال با موفقیت به‌روزرسانی شد.');
      } else {
        await create(formData);
        notifySuccess(`سوال ${formData.section_name} با موفقیت در بانک سوالات ثبت شد.`);
      }
      setEditingQuestion(null);
      setFormOpen(false);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت سوال.';
      notifyError(msg);
      throw err;
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
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'امکان حذف این سوال وجود ندارد.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        title="بانک سوالات"
        crumb={['پوهنتون کندز', 'بانک سوالات']}
        actions={
          canManage && (
            <div className="flex gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setEditingQuestion(null);
                  setFormSection('Grammar');
                  setFormOpen(true);
                }}
              >
                + سوال گرامر
              </Button>
              <Button
                variant="gold"
                onClick={() => {
                  setEditingQuestion(null);
                  setFormSection(selectedSection && selectedSection !== 'Grammar' ? selectedSection : 'Listening');
                  setFormOpen(true);
                }}
              >
                + سوال لیسنینگ
              </Button>
            </div>
          )
        }
      />

      {/* Section Quick Filter Badges */}
      <div className="flex items-center gap-2 mb-3.5 flex-wrap">
        {SECTION_BADGES.map((b) => {
          const isSelected = selectedSection === b.key;
          return (
            <button
              key={b.key}
              type="button"
              onClick={() => {
                setSelectedSection(b.key);
                setPage(1);
              }}
              className={`badge cursor-pointer transition-colors ${
                isSelected ? 'on' : 'off hover:bg-paper'
              }`}
            >
              {b.label}
            </button>
          );
        })}
      </div>

      <FilterBar>
        <FilterInput
          value={filters.search}
          onChange={(e) => {
            setFilters((prev) => ({ ...prev, search: e.target.value }));
            setPage(1);
          }}
          placeholder="جستجو در متن سوال… مثلاً Present Perfect"
        />
        <FilterSelect
          value={filters.difficulty}
          onChange={(e) => {
            setFilters((prev) => ({ ...prev, difficulty: e.target.value }));
            setPage(1);
          }}
        >
          <option value="">سطح دشواری: همه</option>
          <option value="easy">آسان</option>
          <option value="medium">متوسط</option>
          <option value="hard">سخت</option>
        </FilterSelect>
        <FilterInput
          value={filters.topic}
          onChange={(e) => {
            setFilters((prev) => ({ ...prev, topic: e.target.value }));
            setPage(1);
          }}
          placeholder="موضوع: مثلاً Tenses"
          className="max-w-[160px]"
        />
        <FilterSelect
          value={filters.status}
          onChange={(e) => {
            setFilters((prev) => ({ ...prev, status: e.target.value }));
            setPage(1);
          }}
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
          emptyTitle="هیچ سوالی با این مشخصات در بانک سوالات یافت نشد."
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

      <QuestionFormModal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditingQuestion(null);
        }}
        onSave={handleSave}
        question={editingQuestion}
        initialSection={formSection}
        busy={busy}
      />

      <QuestionVersionModal
        open={!!versionModalQuestion}
        onClose={() => setVersionModalQuestion(null)}
        question={versionModalQuestion}
        onGetVersions={getVersions}
      />

      {/* دیالوگ تأیید حذف سوال فقط برای Draft */}
      <ConfirmDialog
        open={!!deletingQuestion}
        onClose={() => setDeletingQuestion(null)}
        onConfirm={handleDelete}
        title="حذف سوال"
        message={`آیا از حذف این سوال پیش‌نویس (Draft) اطمینان دارید؟`}
        confirmTone="danger"
        confirmLabel="حذف سوال"
        busy={busy}
      />
    </>
  );
}
