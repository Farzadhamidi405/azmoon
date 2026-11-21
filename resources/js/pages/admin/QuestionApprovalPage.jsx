import { useMemo, useState } from 'react';
import {
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  FilterBar,
  FilterInput,
  PageHeader,
  Pagination,
} from '../../components/ui/index.js';
import useQuestions from '../../hooks/useQuestions.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { canChangeQuestionStatus } from '../../lib/permissions.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function QuestionApprovalPage() {
  const { user } = useAuth();
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  // واکشی سوالات با وضعیت under_review به همراه جستجو و صفحه‌بندی
  const { items, pagination, loading, error, reload, changeStatus } = useQuestions({
    status: 'under_review',
    search: search || undefined,
    page,
    per_page: 15,
  });

  const [rejectingQuestion, setRejectingQuestion] = useState(null);
  const [actionBusyId, setActionBusyId] = useState(null);
  const [expandedId, setExpandedId] = useState(null);

  // عملیات تأیید سوال (انتقال وضعیت به approved)
  const handleApprove = async (question) => {
    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'approved');
      notifySuccess(`سوال «${question.section_name}» با موفقیت تأیید شد.`);
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در تأیید سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // عملیات رد سوال (انتقال وضعیت به draft)
  const handleRejectConfirm = async () => {
    if (!rejectingQuestion) return;
    setActionBusyId(rejectingQuestion.id);
    try {
      await changeStatus(rejectingQuestion.id, 'draft');
      notifySuccess(`سوال به پیش‌نویس (Draft) بازگردانده شد.`);
      setRejectingQuestion(null);
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در رد سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'question_text',
        title: 'متن سوال و گزینه‌ها',
        render: (_, row) => {
          const isExpanded = expandedId === row.id;
          return (
            <div className="space-y-1.5 py-1">
              <div className="font-semibold text-ink leading-relaxed">
                {row.question_text}
              </div>
              <div className="text-xs text-ink-soft flex items-center gap-2 flex-wrap">
                {row.subject?.name && (
                  <span className="font-medium text-navy">{row.subject.name}</span>
                )}
                {row.topic && <span>· موضوع: {row.topic}</span>}
                {row.score && (
                  <span className="font-mono">· نمره: {row.score}</span>
                )}
              </div>

              {/* باز/بسته کردن گزینه‌های سوال */}
              {Array.isArray(row.options) && row.options.length > 0 && (
                <div className="pt-1">
                  <button
                    type="button"
                    onClick={() => setExpandedId(isExpanded ? null : row.id)}
                    className="text-xs text-navy hover:underline font-medium inline-flex items-center gap-1 cursor-pointer"
                  >
                    <span>{isExpanded ? '▲ بستن گزینه‌ها' : `▼ مشاهده ${row.options.length} گزینه`}</span>
                  </button>

                  {isExpanded && (
                    <div className="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 p-2.5 bg-paper/60 rounded-lg border border-line">
                      {row.options.map((opt, idx) => (
                        <div
                          key={opt.id || idx}
                          className={`text-xs p-2 rounded border flex items-start gap-2 ${
                            opt.is_correct
                              ? 'border-emerald-500/50 bg-emerald-50 text-emerald-950 font-bold'
                              : 'border-line bg-panel text-ink'
                          }`}
                        >
                          <span className="font-mono text-[11px] shrink-0 font-bold">
                            {String.fromCharCode(65 + idx)}.
                          </span>
                          <span className="flex-1">{opt.option_text}</span>
                          {opt.is_correct && (
                            <span className="text-[10px] text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded font-bold shrink-0">
                              پاسخ صحیح ✓
                            </span>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          );
        },
      },
      {
        key: 'section_name',
        title: 'بخش',
        render: (sec) => (
          <span className="text-xs font-bold text-navy">
            {sec || 'Grammar'}
          </span>
        ),
      },
      {
        key: 'creator',
        title: 'طراح سوال',
        render: (_, row) => (
          <div className="text-xs">
            <div className="font-semibold text-ink">{row.created_by_user?.name || row.createdBy?.name || '—'}</div>
            <div className="text-ink-soft text-[11px] font-mono">
              {row.created_by_user?.email || row.createdBy?.email || ''}
            </div>
          </div>
        ),
      },
      {
        key: 'status',
        title: 'وضعیت',
        render: () => <Badge tone="pending">در انتظار بررسی</Badge>,
      },
      {
        key: 'actions',
        title: 'عملیات بررسی',
        align: 'end',
        render: (_, row) => {
          const canAction = canChangeQuestionStatus(user, row);
          const isBusy = actionBusyId === row.id;

          if (!canAction) {
            return <span className="text-xs text-ink-soft">فاقد مجوز</span>;
          }

          return (
            <div className="flex items-center justify-end gap-1.5">
              <Button
                size="sm"
                variant="teal"
                loading={isBusy}
                disabled={actionBusyId !== null && !isBusy}
                onClick={() => handleApprove(row)}
              >
                تأیید ✓
              </Button>
              <Button
                size="sm"
                variant="outline"
                className="text-red hover:bg-red/10 border-red/40"
                disabled={actionBusyId !== null}
                onClick={() => setRejectingQuestion(row)}
              >
                رد ✕
              </Button>
            </div>
          );
        },
      },
    ],
    [user, expandedId, actionBusyId]
  );

  return (
    <>
      <PageHeader
        title="تأیید سوالات"
        crumb={['بانک سوالات', 'تأیید سوالات']}
        actions={
          <Button variant="outline" size="sm" onClick={() => reload()}>
            تازه‌سازی
          </Button>
        }
      />

      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder="جستجو در متن سوالات در حال بررسی..."
          className="max-w-md"
        />
      </FilterBar>

      <div className="card">
        {error ? (
          <div className="p-4 rounded-xl border border-red/30 bg-red/5 text-xs text-red">
            {error.apiMessage || 'خطا در بارگذاری سوالات در انتظار بررسی.'}
          </div>
        ) : (
          <DataTable
            columns={columns}
            data={items}
            loading={loading}
            emptyTitle="هیچ سوالی در انتظار بررسی وجود ندارد."
          />
        )}

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

      <ConfirmDialog
        open={!!rejectingQuestion}
        onClose={() => setRejectingQuestion(null)}
        onConfirm={handleRejectConfirm}
        title="رد سوال و بازگشت به پیش‌نویس"
        message={`آیا از رد این سوال اطمینان دارید؟ سوال به وضعیت پیش‌نویس (Draft) طراح بازمی‌گردد تا اصلاحات لازم انجام شود.`}
        confirmTone="danger"
        confirmLabel="رد سوال"
        busy={actionBusyId !== null}
      />
    </>
  );
}
