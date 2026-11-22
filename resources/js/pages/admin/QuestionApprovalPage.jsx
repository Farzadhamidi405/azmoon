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
import useQuestions from '../../hooks/useQuestions.js';
import useSubjects from '../../hooks/useSubjects.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { canChangeQuestionStatus } from '../../lib/permissions.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';
import formatDate from '../../lib/formatDate.js';
import QuestionDetailModal from '../../components/questions/QuestionDetailModal.jsx';
import RejectReasonModal from '../../components/questions/RejectReasonModal.jsx';

const STATUS_TABS = [
  { key: 'under_review', label: 'در انتظار بررسی (Pending)' },
  { key: 'approved', label: 'تأیید شده (Approved)' },
  { key: 'rejected', label: 'رد شده (Rejected)' },
  { key: '', label: 'همه سوالات' },
];

const TYPE_OPTIONS = [
  { value: '', label: 'همه انواع' },
  { value: 'single', label: 'چهارگزینه‌ای (تک‌پاسخی)' },
  { value: 'multi', label: 'چندگزینه‌ای' },
  { value: 'true_false', label: 'صحیح / غلط' },
  { value: 'short_answer', label: 'پاسخ کوتاه' },
];

const STATUS_BADGES = {
  under_review: { label: 'در انتظار بررسی', tone: 'pending' },
  approved: { label: 'تأیید شده', tone: 'teal' },
  rejected: { label: 'رد شده', tone: 'bad' },
  draft: { label: 'پیش‌نویس', tone: 'off' },
  active: { label: 'فعال', tone: 'on' },
  inactive: { label: 'غیرفعال', tone: 'bad' },
  archived: { label: 'آرشیو', tone: 'off' },
};

export default function QuestionApprovalPage() {
  const { user } = useAuth();
  const { items: subjects } = useSubjects();

  const [activeTab, setActiveTab] = useState('under_review');
  const [search, setSearch] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [questionType, setQuestionType] = useState('');
  const [topic, setTopic] = useState('');
  const [page, setPage] = useState(1);

  // واکشی سوالات با فیلترها و وضعیت فعال
  const { items, pagination, loading, error, reload, changeStatus } = useQuestions({
    status: activeTab || undefined,
    subject_id: subjectId || undefined,
    question_type: questionType || undefined,
    topic: topic || undefined,
    search: search || undefined,
    page,
    per_page: 15,
  });

  const [detailQuestion, setDetailQuestion] = useState(null);
  const [rejectingQuestion, setRejectingQuestion] = useState(null);
  const [actionBusyId, setActionBusyId] = useState(null);
  const [expandedId, setExpandedId] = useState(null);

  // عملیات تأیید سوال (انتقال وضعیت به approved)
  const handleApprove = async (question) => {
    if (question.status !== 'under_review') {
      notifyError('تنها سوالاتی که در انتظار بررسی هستند قابل تأیید می‌باشند.');
      return;
    }

    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'approved');
      notifySuccess(`سوال شماره #${question.id} با موفقیت تأیید شد.`);
      if (detailQuestion?.id === question.id) {
        setDetailQuestion(null);
      }
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در تأیید سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // عملیات رد سوال (انتقال وضعیت به rejected همراه با دلیل رد)
  const handleRejectConfirm = async (question, reason) => {
    if (question.status !== 'under_review') {
      notifyError('تنها سوالاتی که در انتظار بررسی هستند قابل رد می‌باشند.');
      return;
    }

    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'rejected', { rejection_reason: reason });
      notifySuccess(`سوال شماره #${question.id} با موفقیت رد شد و دلیل رد به طراح اعلام گردید.`);
      setRejectingQuestion(null);
      if (detailQuestion?.id === question.id) {
        setDetailQuestion(null);
      }
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت رد سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  const columns = useMemo(
    () => [
      {
        key: 'question_text',
        header: 'متن سوال و گزینه‌ها',
        title: 'متن سوال و گزینه‌ها',
        primary: true,
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const isExpanded = expandedId === row.id;
          return (
            <div className="space-y-1.5 py-1 max-w-[480px]">
              <div className="font-semibold text-ink leading-relaxed">
                {row.question_text || '—'}
              </div>

              <div className="text-xs text-ink-soft flex items-center gap-2 flex-wrap">
                {row.subject?.name && (
                  <span className="font-semibold text-navy">{row.subject.name}</span>
                )}
                {row.section_name && <span>· بخش: {row.section_name}</span>}
                {row.topic && <span>· موضوع: {row.topic}</span>}
                {row.score && (
                  <span className="font-mono font-bold text-navy">· نمره: {row.score}</span>
                )}
                {row.media && (
                  <span className="text-[11px] bg-paper px-1.5 py-0.5 rounded border border-line">
                    🎧 رسانه
                  </span>
                )}
                {row.passage && (
                  <span className="text-[11px] bg-paper px-1.5 py-0.5 rounded border border-line">
                    📖 درک مطلب
                  </span>
                )}
              </div>

              {/* نمایش دلیل رد در صورت رد شدن سوال */}
              {row.rejection_reason && (
                <div className="mt-1.5 p-2 rounded-lg bg-red/10 border border-red/30 text-red text-[11.5px] leading-relaxed">
                  <span className="font-bold">دلیل رد: </span>
                  {row.rejection_reason}
                </div>
              )}

              {/* پیش‌نمایش گزینه‌ها */}
              {Array.isArray(row.options) && row.options.length > 0 && (
                <div className="pt-1">
                  <button
                    type="button"
                    onClick={() => setExpandedId(isExpanded ? null : row.id)}
                    className="text-xs text-navy hover:underline font-semibold inline-flex items-center gap-1 cursor-pointer"
                  >
                    <span>{isExpanded ? '▲ بستن گزینه‌ها' : `▼ مشاهده ${row.options.length} گزینه`}</span>
                  </button>

                  {isExpanded && (
                    <div className="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 p-2.5 bg-paper/60 rounded-xl border border-line">
                      {row.options.map((opt, idx) => (
                        <div
                          key={opt.id || idx}
                          className={`text-xs p-2 rounded-lg border flex items-start gap-2 ${
                            opt.is_correct
                              ? 'border-emerald-500/60 bg-emerald-50 text-emerald-950 font-bold'
                              : 'border-line bg-panel text-ink'
                          }`}
                        >
                          <span className="font-mono text-[11px] shrink-0 font-bold">
                            {String.fromCharCode(65 + idx)}.
                          </span>
                          <span className="flex-1">{opt.option_text}</span>
                          {opt.is_correct && (
                            <span className="text-[10px] text-emerald-800 bg-emerald-100 px-1.5 py-0.5 rounded font-bold shrink-0">
                              پاسخ صحیح ✓
                            </span>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}

              {row.question_type === 'short_answer' && row.answer_key && (
                <div className="text-[11px] text-emerald-800 font-mono font-medium">
                  کلید پاسخ: {row.answer_key}
                </div>
              )}
            </div>
          );
        },
      },
      {
        key: 'creator',
        header: 'طراح و تاریخ',
        title: 'طراح و تاریخ',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          return (
            <div className="text-xs">
              <div className="font-semibold text-ink">
                {row.created_by_user?.name || row.createdBy?.name || '—'}
              </div>
              <div className="text-ink-soft text-[11px] font-mono mt-0.5">
                {row.created_at ? formatDate(row.created_at) : ''}
              </div>
            </div>
          );
        },
      },
      {
        key: 'status',
        header: 'وضعیت',
        title: 'وضعیت',
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const cfg = STATUS_BADGES[row.status] || { label: row.status, tone: 'off' };
          return (
            <div>
              <Badge tone={cfg.tone}>{cfg.label}</Badge>
              {row.reviewed_by_user?.name && (
                <div className="text-[10px] text-ink-soft mt-1">
                  بررسی: {row.reviewed_by_user.name}
                </div>
              )}
            </div>
          );
        },
      },
      {
        key: 'actions',
        header: 'عملیات بررسی',
        title: 'عملیات بررسی',
        align: 'end',
        actions: true,
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const canAction = canChangeQuestionStatus(user, row);
          const isBusy = actionBusyId === row.id;
          const isUnderReview = row.status === 'under_review';

          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setDetailQuestion(row)}
              >
                مشاهده جزئیات
              </Button>

              {canAction && isUnderReview && (
                <>
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
                </>
              )}
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
        title="تأیید سوالات (Approval Workflow)"
        crumb={['بانک سوالات', 'تأیید سوالات']}
        actions={
          <Button variant="outline" size="sm" onClick={() => reload()}>
            تازه‌سازی
          </Button>
        }
      />

      {/* Stepper راهنمای گردش کار تأیید */}
      <div className="stepper mb-4">
        <div className="step done">Draft (طراحی)</div>
        <div className="step-arrow">←</div>
        <div className={`step ${activeTab === 'under_review' ? 'cur' : 'done'}`}>
          Under Review (در انتظار تأیید)
        </div>
        <div className="step-arrow">←</div>
        <div className={`step ${activeTab === 'approved' ? 'cur' : ''}`}>
          Approved (تأیید شده)
        </div>
        <div className="step-arrow">←</div>
        <div className="step">Active در آزمون‌ها</div>
      </div>

      {/* تب‌های انتخاب وضعیت سوالات */}
      <div className="flex items-center gap-2 mb-3.5 flex-wrap">
        {STATUS_TABS.map((tab) => {
          const isSelected = activeTab === tab.key;
          return (
            <button
              key={tab.key}
              type="button"
              onClick={() => {
                setActiveTab(tab.key);
                setPage(1);
              }}
              className={`badge cursor-pointer transition-colors ${
                isSelected ? 'on font-bold' : 'off hover:bg-paper'
              }`}
            >
              {tab.label}
            </button>
          );
        })}
      </div>

      {/* نوار فیلتر و جستجو */}
      <FilterBar>
        <FilterInput
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder="جستجو در متن سوال..."
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
          value={questionType}
          onChange={(e) => {
            setQuestionType(e.target.value);
            setPage(1);
          }}
        >
          {TYPE_OPTIONS.map((t) => (
            <option key={t.value} value={t.value}>
              {t.label}
            </option>
          ))}
        </FilterSelect>

        <FilterInput
          value={topic}
          onChange={(e) => {
            setTopic(e.target.value);
            setPage(1);
          }}
          placeholder="موضوع..."
          className="max-w-[140px]"
        />
      </FilterBar>

      <div className="card">
        {error ? (
          <div className="p-4 rounded-xl border border-red/30 bg-red/5 text-xs text-red">
            {error.apiMessage || 'خطا در بارگذاری سوالات.'}
          </div>
        ) : (
          <DataTable
            columns={columns}
            rows={items}
            data={items}
            loading={loading}
            emptyTitle={
              activeTab === 'under_review'
                ? 'هیچ سوالی در حال حاضر در انتظار بررسی وجود ندارد.'
                : activeTab === 'approved'
                ? 'هیچ سوال تأییدشده‌ای یافت نشد.'
                : activeTab === 'rejected'
                ? 'هیچ سوال ردشده‌ای یافت نشد.'
                : 'هیچ سوالی با این مشخصات یافت نشد.'
            }
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

      <div className="helper mt-3 text-xs text-ink-soft leading-relaxed">
        فقط سوالات با وضعیت Approved (تأییدشده) می‌توانند در آزمون‌ها انتخاب شده و در Question Pool قرار گیرند.
      </div>

      {/* Modal جزئیات کامل سوال */}
      <QuestionDetailModal
        open={!!detailQuestion}
        onClose={() => setDetailQuestion(null)}
        question={detailQuestion}
        onApprove={handleApprove}
        onReject={(q) => {
          setRejectingQuestion(q);
        }}
        canAction={canChangeQuestionStatus(user, detailQuestion)}
        busy={actionBusyId !== null}
      />

      {/* Modal رد سوال با ثبت دلیل رد */}
      <RejectReasonModal
        open={!!rejectingQuestion}
        onClose={() => setRejectingQuestion(null)}
        onConfirm={handleRejectConfirm}
        question={rejectingQuestion}
        busy={actionBusyId !== null}
      />
    </>
  );
}
