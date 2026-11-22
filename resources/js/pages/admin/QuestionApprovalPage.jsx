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

// تب‌های گردش کار تأیید سوالات مطابق با SRS
const STATUS_TABS = [
  { key: 'under_review', label: 'Under Review (در انتظار تأیید)' },
  { key: 'draft', label: 'Draft (پیش‌نویس)' },
  { key: 'approved', label: 'Approved (تأیید شده)' },
  { key: 'active', label: 'Active (فعال در آزمون‌ها)' },
  { key: 'inactive', label: 'Inactive (غیرفعال)' },
  { key: 'archived', label: 'Archived (آرشیو)' },
];

const TYPE_OPTIONS = [
  { value: '', label: 'همه انواع سوال' },
  { value: 'single', label: 'چهارگزینه‌ای (تک‌پاسخی)' },
  { value: 'multi', label: 'چندگزینه‌ای' },
  { value: 'true_false', label: 'صحیح / غلط' },
  { value: 'short_answer', label: 'پاسخ کوتاه' },
];

const STATUS_BADGES = {
  under_review: { label: 'Under Review', tone: 'pending' },
  draft: { label: 'Draft', tone: 'off' },
  approved: { label: 'Approved', tone: 'teal' },
  active: { label: 'Active', tone: 'on' },
  inactive: { label: 'Inactive', tone: 'bad' },
  archived: { label: 'Archived', tone: 'off' },
};

export default function QuestionApprovalPage() {
  const { user, hasRole } = useAuth();
  const { items: subjects } = useSubjects();

  const isSuperAdmin = hasRole('Super Admin', 'Exam Administrator');
  const isReviewer = hasRole('Reviewer') || isSuperAdmin;
  const isQuestionManager = hasRole('Question Manager') || isSuperAdmin;

  const [activeTab, setActiveTab] = useState('under_review');
  const [search, setSearch] = useState('');
  const [subjectId, setSubjectId] = useState('');
  const [questionType, setQuestionType] = useState('');
  const [topic, setTopic] = useState('');
  const [page, setPage] = useState(1);

  // واکشی سوالات بر اساس وضعیت تب جاری و فیلترها
  const { items, pagination, counts, loading, error, reload, changeStatus } = useQuestions({
    status: activeTab,
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

  // تأیید سوال (under_review -> approved)
  const handleApprove = async (question) => {
    if (question.status !== 'under_review') {
      notifyError('تنها سوالات در انتظار بررسی قابل تأیید هستند.');
      return;
    }

    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'approved');
      notifySuccess(`سوال #${question.id} با موفقیت تأیید شد.`);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در تأیید سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // رد سوال (under_review -> draft) همراه با ثبت دلیل رد در دیتابیس
  const handleRejectConfirm = async (question, reason) => {
    if (question.status !== 'under_review') {
      notifyError('تنها سوالات در انتظار بررسی قابل رد هستند.');
      return;
    }

    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'draft', { rejection_reason: reason });
      notifySuccess(`سوال #${question.id} رد شد و همراه با بازخورد به پیش‌نویس (Draft) طراح بازگردانده شد.`);
      setRejectingQuestion(null);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در رد سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // ارسال سوال برای بررسی (draft -> under_review)
  const handleSubmitForReview = async (question) => {
    if (question.status !== 'draft') {
      notifyError('تنها سوالات در وضعیت پیش‌نویس قابل ارسال برای بررسی هستند.');
      return;
    }

    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'under_review');
      notifySuccess(`سوال #${question.id} برای بررسی (Under Review) ارسال شد.`);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ارسال سوال برای بررسی.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // تغییر وضعیت به فعال (approved -> active یا inactive -> active)
  const handleActivate = async (question) => {
    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'active');
      notifySuccess(`سوال #${question.id} فعال و آماده استفاده در آزمون‌ها شد.`);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در فعال‌سازی سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // تغییر وضعیت به غیرفعال (approved/active -> inactive)
  const handleDeactivate = async (question) => {
    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'inactive');
      notifySuccess(`سوال #${question.id} غیرفعال شد.`);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در غیرفعال‌سازی سوال.';
      notifyError(msg);
    } finally {
      setActionBusyId(null);
    }
  };

  // تغییر وضعیت به بایگانی (active/inactive -> archived)
  const handleArchive = async (question) => {
    setActionBusyId(question.id);
    try {
      await changeStatus(question.id, 'archived');
      notifySuccess(`سوال #${question.id} بایگانی شد.`);
      if (detailQuestion?.id === question.id) setDetailQuestion(null);
      reload();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در بایگانی سوال.';
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

              {/* نمایش بازخورد رد سوال در صورت وجود */}
              {row.rejection_reason && (
                <div className="mt-1.5 p-2 rounded-lg bg-amber-50 border border-amber-300 text-amber-950 text-[11.5px] leading-relaxed">
                  <span className="font-bold text-amber-900">⚠️ بازخورد بازبین: </span>
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
        header: 'عملیات',
        title: 'عملیات',
        align: 'end',
        actions: true,
        render: (...args) => {
          const row = (args[0] && typeof args[0] === 'object' ? args[0] : args[1]) || {};
          const canAction = canChangeQuestionStatus(user, row);
          const isBusy = actionBusyId === row.id;

          return (
            <div className="flex items-center justify-end gap-1.5 flex-wrap">
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setDetailQuestion(row)}
              >
                جزئیات
              </Button>

              {/* اکشن‌های تب Under Review */}
              {canAction && row.status === 'under_review' && isReviewer && (
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

              {/* اکشن‌های تب Draft */}
              {canAction && row.status === 'draft' && (
                <Button
                  size="sm"
                  variant="teal"
                  loading={isBusy}
                  disabled={actionBusyId !== null && !isBusy}
                  onClick={() => handleSubmitForReview(row)}
                >
                  ارسال برای بررسی ↑
                </Button>
              )}

              {/* اکشن‌های تب Approved */}
              {canAction && row.status === 'approved' && (
                <>
                  <Button
                    size="sm"
                    variant="gold"
                    loading={isBusy}
                    disabled={actionBusyId !== null && !isBusy}
                    onClick={() => handleActivate(row)}
                  >
                    فعال در آزمون
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    className="text-ink-soft hover:bg-paper"
                    disabled={actionBusyId !== null}
                    onClick={() => handleDeactivate(row)}
                  >
                    غیرفعال
                  </Button>
                </>
              )}

              {/* اکشن‌های تب Active */}
              {canAction && row.status === 'active' && (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    className="text-ink-soft hover:bg-paper"
                    disabled={actionBusyId !== null}
                    onClick={() => handleDeactivate(row)}
                  >
                    غیرفعال
                  </Button>
                  {isSuperAdmin && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-red hover:bg-red/10"
                      disabled={actionBusyId !== null}
                      onClick={() => handleArchive(row)}
                    >
                      آرشیو
                    </Button>
                  )}
                </>
              )}

              {/* اکشن‌های تب Inactive */}
              {canAction && row.status === 'inactive' && (
                <>
                  <Button
                    size="sm"
                    variant="gold"
                    loading={isBusy}
                    disabled={actionBusyId !== null && !isBusy}
                    onClick={() => handleActivate(row)}
                  >
                    فعال‌سازی مجدد
                  </Button>
                  {isSuperAdmin && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-red hover:bg-red/10"
                      disabled={actionBusyId !== null}
                      onClick={() => handleArchive(row)}
                    >
                      آرشیو
                    </Button>
                  )}
                </>
              )}
            </div>
          );
        },
      },
    ],
    [user, expandedId, actionBusyId, isReviewer, isSuperAdmin]
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

      {/* Stepper راهنمای گردش کار تأیید سوال مطابق با SRS */}
      <div className="stepper mb-4">
        <div className={`step ${activeTab === 'draft' ? 'cur' : 'done'}`}>
          ۱. Draft (طراحی)
        </div>
        <div className="step-arrow">←</div>
        <div className={`step ${activeTab === 'under_review' ? 'cur' : activeTab === 'draft' ? '' : 'done'}`}>
          ۲. Under Review (بررسی)
        </div>
        <div className="step-arrow">←</div>
        <div className={`step ${activeTab === 'approved' ? 'cur' : ['active', 'inactive', 'archived'].includes(activeTab) ? 'done' : ''}`}>
          ۳. Approved (تأییدشده)
        </div>
        <div className="step-arrow">←</div>
        <div className={`step ${activeTab === 'active' ? 'cur' : ''}`}>
          ۴. Active (آماده در آزمون)
        </div>
      </div>

      {/* تب‌های ۶ گانه مستقل با شمارنده تعداد رکوردها */}
      <div className="flex items-center gap-2 mb-3.5 flex-wrap">
        {STATUS_TABS.map((tab) => {
          const isSelected = activeTab === tab.key;
          const count = counts ? counts[tab.key] ?? 0 : 0;
          return (
            <button
              key={tab.key}
              type="button"
              onClick={() => {
                setActiveTab(tab.key);
                setPage(1);
              }}
              className={`badge cursor-pointer transition-all flex items-center gap-1.5 py-1 px-3 ${
                isSelected
                  ? 'on font-bold shadow-xs scale-[1.02]'
                  : 'off hover:bg-paper'
              }`}
            >
              <span>{tab.label}</span>
              <span className={`text-[11px] px-1.5 py-0.2 rounded-full font-mono ${
                isSelected ? 'bg-panel text-navy font-bold' : 'bg-line-soft text-ink-soft'
              }`}>
                {count}
              </span>
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
                : activeTab === 'draft'
                ? 'هیچ سوالی در وضعیت پیش‌نویس (Draft) یافت نشد.'
                : activeTab === 'approved'
                ? 'هیچ سوال تأییدشده‌ای یافت نشد.'
                : activeTab === 'active'
                ? 'هیچ سوال فعالی یافت نشد.'
                : activeTab === 'inactive'
                ? 'هیچ سوال غیرفعالی یافت نشد.'
                : 'هیچ سوالی با این مشخصات در این بخش یافت نشد.'
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
        مطابق بندهای سند مشخصات سامانه (SRS)، فقط سوالات با وضعیت Approved یا Active مجاز به ورود به آزمون‌ها و Question Pool هستند. رد سوال توسط بازبین آن را همراه با دلیل به پیش‌نویس بازمی‌گرداند.
      </div>

      {/* Modal جزئیات کامل سوال */}
      <QuestionDetailModal
        open={!!detailQuestion}
        onClose={() => setDetailQuestion(null)}
        question={detailQuestion}
        onApprove={handleApprove}
        onReject={(q) => setRejectingQuestion(q)}
        onSubmitForReview={handleSubmitForReview}
        onActivate={handleActivate}
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
