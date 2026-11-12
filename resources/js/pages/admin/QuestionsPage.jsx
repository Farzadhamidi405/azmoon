import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
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
  active: { label: 'Active', tone: 'on' },
  approved: { label: 'Approved', tone: 'teal' },
  under_review: { label: 'Under Review', tone: 'pending' },
  draft: { label: 'Draft', tone: 'off' },
  inactive: { label: 'Inactive', tone: 'bad' },
  archived: { label: 'Archived', tone: 'off' },
};

const DIFFICULTY_LABELS = {
  easy: 'آسان',
  medium: 'متوسط',
  hard: 'سخت',
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

  const { items: subjects } = useSubjects();
  const [selectedSection, setSelectedSection] = useState('');
  const [filters, setFilters] = useState({ search: '', status: '', difficulty: '', topic: '' });
  const [page, setPage] = useState(1);

  const { items, pagination, loading, reload, create, update, getVersions } = useQuestions({
    ...filters,
    section_name: selectedSection || undefined,
    page,
    per_page: 20,
  });

  const [formOpen, setFormOpen] = useState(false);
  const [formSection, setFormSection] = useState('Grammar');
  const [editingQuestion, setEditingQuestion] = useState(null);
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
        render: (_, row) => (
          <span className="text-xs font-medium text-navy">
            {row.section_name || (row.question_type === 'single_choice' ? 'Grammar' : row.question_type)}
          </span>
        ),
      },
      {
        key: 'options_count',
        title: 'گزینه‌ها',
        render: (_, row) => {
          const count = Array.isArray(row.options) ? row.options.length : 4;
          return <span className="text-xs">{count} گزینه</span>;
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
          <div className="flex items-center justify-end gap-1">
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
                  setFormSection('Vocabulary');
                  setFormOpen(true);
                }}
              >
                + سوال واژگان / سایر
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
    </>
  );
}
