import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import useExams from '../../hooks/useExams.js';
import useSubjects from '../../hooks/useSubjects.js';
import formatDate from '../../lib/formatDate.js';
import ExamFormModal from '../../components/exams/ExamFormModal.jsx';
import ExamQuestionsModal from '../../components/exams/ExamQuestionsModal.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { canChangeExamStatus } from '../../lib/permissions.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

const STATUS_CONFIG = {
  running: { label: 'در حال اجرا (فعال)', tone: 'on' },
  published: { label: 'منتشرشده', tone: 'pending' },
  scheduled: { label: 'زمان‌بندی‌شده', tone: 'pending' },
  approved: { label: 'تأییدشده', tone: 'teal' },
  under_review: { label: 'در انتظار بررسی', tone: 'pending' },
  draft: { label: 'پیش‌نویس (Draft)', tone: 'off' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  reviewed: { label: 'بررسی‌شده', tone: 'teal' },
  archived: { label: 'آرشیو', tone: 'off' },
};

export default function ExamsPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { items: subjects } = useSubjects();
  const canChange = canChangeExamStatus(user);

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [dateFilter, setDateFilter] = useState('');

  const examsHook = useExams({
    search: search || undefined,
    subject_id: subjectId || undefined,
    status: statusFilter || undefined,
    date: dateFilter || undefined,
    page,
    per_page: 15,
  });

  const { items, pagination, loading, reload, update, changeStatus, remove } = examsHook;

  const [editingExam, setEditingExam] = useState(null);
  const [questionsExam, setQuestionsExam] = useState(null);
  const [formOpen, setFormOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  // پایان دستی آزمون (running -> completed)
  const [completingExam, setCompletingExam] = useState(null);
  const [completingBusy, setCompletingBusy] = useState(false);

  // حذف آزمون (draft)
  const [deletingExam, setDeletingExam] = useState(null);
  const [deletingBusy, setDeletingBusy] = useState(false);

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

  const handleConfirmDelete = async () => {
    if (!deletingExam) return;
    setDeletingBusy(true);
    try {
      await remove(deletingExam.id);
      notifySuccess(`آزمون «${deletingExam.title}» با موفقیت حذف گردید.`);
      setDeletingExam(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در حذف آزمون.';
      notifyError(msg);
    } finally {
      setDeletingBusy(false);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'title',
        header: 'عنوان آزمون و مضمون',
        title: 'عنوان آزمون و مضمون',
        primary: true,
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          return (
            <div>
              <div className="font-bold text-navy text-[13px]">{row.title || '—'}</div>
              <div className="text-xs text-ink-soft mt-0.5">
                {row.subject?.name ? `${row.subject.name} ` : ''}
                {row.description ? `· ${row.description}` : ''}
              </div>
            </div>
          );
        },
      },
      {
        key: 'questions_count',
        header: 'سوالات',
        title: 'سوالات',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const qCount = row.total_questions ?? row.questions_count ?? 0;
          return (
            <span className="font-mono text-xs font-bold text-navy">
              {qCount > 0 ? `${qCount} سوال` : '—'}
            </span>
          );
        },
      },
      {
        key: 'duration_minutes',
        header: 'مدت و نمرات',
        title: 'مدت و نمرات',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          return (
            <div className="text-xs">
              <div>{row.duration_minutes ? `${row.duration_minutes} دقیقه` : '—'}</div>
              <div className="text-[11px] text-ink-soft mt-0.5">
                کل: <span className="font-mono">{row.total_marks ?? 100}</span> | قبولی:{' '}
                <span className="font-mono">{row.pass_marks ?? 60}</span>
              </div>
            </div>
          );
        },
      },
      {
        key: 'created_at',
        header: 'تاریخ ثبت',
        title: 'تاریخ ثبت',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          return (
            <span className="text-xs text-ink-soft font-mono">
              {row.created_at ? formatDate(row.created_at) : '—'}
            </span>
          );
        },
      },
      {
        key: 'status',
        header: 'وضعیت',
        title: 'وضعیت',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const st = row.status || (typeof args[0] === 'string' ? args[0] : 'draft');
          const cfg = STATUS_CONFIG[st] || { label: st, tone: 'off' };
          return <Badge tone={cfg.tone}>{cfg.label}</Badge>;
        },
      },
      {
        key: 'actions',
        header: 'عملیات',
        title: 'عملیات',
        align: 'end',
        actions: true,
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const isDraft = row.status === 'draft';
          const isEditable = ['draft', 'under_review'].includes(row.status);

          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              {/* دکمه مدیریت سوالات آزمون */}
              <Button
                size="sm"
                variant="outline"
                className="text-navy font-semibold"
                onClick={() => setQuestionsExam(row)}
              >
                سوالات آزمون
              </Button>

              {/* ویرایش یا مشاهده */}
              <Button
                size="sm"
                variant="ghost"
                onClick={() => navigate(`/admin/exams/${row.id}/edit`)}
              >
                {isEditable ? 'ویرایش' : 'مشاهده'}
              </Button>

              {/* پایان آزمون */}
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

              {/* حذف آزمون فقط برای draft */}
              {isDraft && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red hover:bg-red/10"
                  onClick={() => setDeletingExam(row)}
                >
                  حذف
                </Button>
              )}
            </div>
          );
        },
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
        title="مدیریت آزمون‌ها"
        crumb={['پوهنتون کندز', 'آزمون‌ها']}
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <Button variant="outline" onClick={() => navigate('/admin/questions')}>
              بانک سوالات
            </Button>
            <Button variant="outline" onClick={() => navigate('/admin/exams/pool')}>
              Question Pool
            </Button>
            <Button variant="gold" onClick={() => navigate('/admin/exams/create')}>
              + ایجاد آزمون جدید
            </Button>
          </div>
        }
      />

      {/* نوار فیلتر و جستجو */}
      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder="جستجو در عنوان آزمون..."
          className="max-w-xs"
        />

        <FilterSelect
          value={subjectId}
          onChange={(e) => {
            setSubjectId(e.target.value);
            setPage(1);
          }}
        >
          <option value="">مضمون: همه</option>
          {subjects.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </FilterSelect>

        <FilterSelect
          value={statusFilter}
          onChange={(e) => {
            setStatusFilter(e.target.value);
            setPage(1);
          }}
        >
          <option value="">وضعیت: همه</option>
          <option value="draft">پیش‌نویس (Draft)</option>
          <option value="under_review">در انتظار بررسی</option>
          <option value="approved">تأییدشده</option>
          <option value="published">منتشرشده</option>
          <option value="running">در حال اجرا (فعال)</option>
          <option value="completed">تکمیل‌شده</option>
          <option value="archived">آرشیو</option>
        </FilterSelect>

        <FilterInput
          type="date"
          value={dateFilter}
          onChange={(e) => {
            setDateFilter(e.target.value);
            setPage(1);
          }}
          className="max-w-[150px]"
        />
      </FilterBar>

      <div className="card">
        <DataTable
          columns={columns}
          rows={items}
          data={items}
          loading={loading}
          emptyTitle="هیچ آزمونی با مشخصات انتخابی یافت نشد."
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

      {/* Modal مدیریت سوالات آزمون */}
      <ExamQuestionsModal
        open={!!questionsExam}
        onClose={() => setQuestionsExam(null)}
        exam={questionsExam}
        onQuestionsUpdated={() => reload()}
        useExamsHook={examsHook}
      />

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

      {/* دیالوگ تأیید حذف آزمون */}
      <ConfirmDialog
        open={!!deletingExam}
        onClose={() => !deletingBusy && setDeletingExam(null)}
        onConfirm={handleConfirmDelete}
        title="حذف آزمون پیش‌نویس"
        message={`آیا از حذف آزمون «${deletingExam?.title}» اطمینان دارید؟`}
        confirmTone="danger"
        confirmLabel="حذف آزمون"
        busy={deletingBusy}
      />
    </>
  );
}
