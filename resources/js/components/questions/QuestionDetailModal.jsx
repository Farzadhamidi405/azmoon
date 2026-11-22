import { Badge, Button, Modal } from '../ui/index.js';
import formatDate from '../../lib/formatDate.js';

const TYPE_LABELS = {
  single: 'چهارگزینه‌ای (تک‌پاسخی)',
  multi: 'چندگزینه‌ای',
  true_false: 'صحیح / غلط',
  short_answer: 'پاسخ کوتاه',
  single_choice: 'چهارگزینه‌ای',
  multiple_choice: 'چندگزینه‌ای',
};

const STATUS_CONFIG = {
  draft: { label: 'پیش‌نویس (Draft)', tone: 'off' },
  under_review: { label: 'در انتظار بررسی (Under Review)', tone: 'pending' },
  approved: { label: 'تأیید شده (Approved)', tone: 'teal' },
  active: { label: 'فعال در آزمون‌ها (Active)', tone: 'on' },
  inactive: { label: 'غیرفعال (Inactive)', tone: 'bad' },
  archived: { label: 'آرشیو (Archived)', tone: 'off' },
};

export default function QuestionDetailModal({
  open,
  onClose,
  question,
  onApprove,
  onReject,
  onSubmitForReview,
  onActivate,
  canAction = false,
  busy = false,
}) {
  if (!question) return null;

  const statusCfg = STATUS_CONFIG[question.status] || {
    label: question.status || 'نامشخص',
    tone: 'off',
  };
  const typeLabel = TYPE_LABELS[question.question_type] || question.question_type || 'تک‌پاسخی';
  const isDraft = question.status === 'draft';
  const isUnderReview = question.status === 'under_review';
  const isApproved = question.status === 'approved';

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`جزئیات سوال شماره #${question.id}`}
      width="max-w-2xl"
    >
      <div className="space-y-4 text-xs">
        {/* نوار وضعیت و اطلاعات پایه */}
        <div className="flex items-center justify-between p-3 rounded-xl bg-paper/60 border border-line">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-ink">وضعیت:</span>
            <Badge tone={statusCfg.tone}>{statusCfg.label}</Badge>
          </div>
          <div className="flex items-center gap-3 text-ink-soft">
            <span>نوع: <strong className="text-navy">{typeLabel}</strong></span>
            <span>نمره: <strong className="text-navy font-mono">{question.score ?? 1}</strong></span>
          </div>
        </div>

        {/* دلیل رد یا بازخورد قبلی در صورت وجود */}
        {question.rejection_reason && (
          <div className="p-3.5 rounded-xl border border-amber-500/40 bg-amber-50/70 text-amber-950 space-y-1">
            <div className="font-bold flex items-center gap-1.5 text-[12.5px] text-amber-900">
              <span>⚠️</span>
              <span>بازخورد بازبین / دلیل رد سوال:</span>
            </div>
            <p className="leading-relaxed whitespace-pre-wrap">{question.rejection_reason}</p>
          </div>
        )}

        {/* اطلاعات موضوع و مضمون */}
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 p-2.5 rounded-lg border border-line bg-panel text-ink">
          <div>
            <span className="text-ink-soft">مضمون:</span>{' '}
            <strong className="text-navy">{question.subject?.name || '—'}</strong>
          </div>
          <div>
            <span className="text-ink-soft">بخش:</span>{' '}
            <strong className="text-navy">{question.section_name || 'Grammar'}</strong>
          </div>
          <div>
            <span className="text-ink-soft">موضوع:</span>{' '}
            <strong className="text-navy">{question.topic || 'عمومی'}</strong>
          </div>
          <div>
            <span className="text-ink-soft">درجه سختی:</span>{' '}
            <span className="font-medium">
              {question.difficulty === 'easy' ? 'آسان' : question.difficulty === 'hard' ? 'سخت' : 'متوسط'}
            </span>
          </div>
          <div>
            <span className="text-ink-soft">طراح:</span>{' '}
            <span className="font-medium">{question.created_by_user?.name || question.createdBy?.name || '—'}</span>
          </div>
          <div>
            <span className="text-ink-soft">تاریخ ثبت:</span>{' '}
            <span className="font-mono">{question.created_at ? formatDate(question.created_at) : '—'}</span>
          </div>
        </div>

        {/* درک مطلب (Passage) در صورت وجود */}
        {question.passage && (
          <div className="p-3.5 rounded-xl border border-line bg-paper/40 space-y-1.5">
            <div className="font-bold text-navy text-[12.5px]">
              متن درک مطلب: {question.passage.title}
            </div>
            <div className="text-ink-soft leading-relaxed max-h-40 overflow-y-auto whitespace-pre-wrap p-2 bg-panel rounded border border-line-soft">
              {question.passage.body_text}
            </div>
          </div>
        )}

        {/* رسانه چندرسانه‌ای (Media) در صورت وجود */}
        {question.media && (
          <div className="p-3 rounded-xl border border-line bg-paper/40 flex items-center gap-3">
            <span className="text-base">🎧</span>
            <div>
              <div className="font-semibold text-navy">فایل ضمیمه: {question.media.title || question.media.type}</div>
              <div className="text-[11px] text-ink-soft font-mono">{question.media.file_path}</div>
            </div>
          </div>
        )}

        {/* متن اصلی سوال */}
        <div className="p-3.5 rounded-xl border border-line bg-paper space-y-1.5">
          <div className="font-bold text-navy text-[13px]">متن سوال:</div>
          <div className="text-[13px] text-ink leading-relaxed whitespace-pre-wrap font-medium">
            {question.question_text}
          </div>
        </div>

        {/* گزینه‌ها یا کلید پاسخ */}
        {question.question_type === 'short_answer' ? (
          <div className="p-3 rounded-xl border border-emerald-500/30 bg-emerald-50/50 space-y-1">
            <div className="font-bold text-emerald-950">کلید پاسخ صحیح (Short Answer Key):</div>
            <div className="text-emerald-900 font-mono text-sm font-semibold">
              {question.answer_key || 'تعریف نشده'}
            </div>
          </div>
        ) : (
          <div className="space-y-2">
            <div className="font-bold text-navy flex items-center justify-between">
              <span>گزینه‌های سوال:</span>
              <span className="text-ink-soft font-normal">
                {Array.isArray(question.options) ? `${question.options.length} گزینه` : 'بدون گزینه'}
              </span>
            </div>

            {Array.isArray(question.options) && question.options.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                {question.options.map((opt, idx) => (
                  <div
                    key={opt.id || idx}
                    className={`p-2.5 rounded-xl border flex items-start gap-2.5 transition-colors ${
                      opt.is_correct
                        ? 'border-emerald-500 bg-emerald-50 text-emerald-950 font-bold shadow-xs'
                        : 'border-line bg-panel text-ink'
                    }`}
                  >
                    <span className="font-mono text-xs font-bold text-ink-soft shrink-0">
                      {String.fromCharCode(65 + idx)}.
                    </span>
                    <span className="flex-1 text-[12px] leading-relaxed">{opt.option_text}</span>
                    {opt.is_correct && (
                      <span className="text-[10px] text-emerald-800 bg-emerald-200/80 px-2 py-0.5 rounded-full font-bold shrink-0">
                        پاسخ صحیح ✓
                      </span>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <div className="p-3 rounded border border-line text-ink-soft text-center">
                گزینه‌ای برای این سوال ثبت نشده است.
              </div>
            )}
          </div>
        )}

        {/* فوتر اکشن‌ها */}
        <div className="flex items-center justify-between pt-3 border-t border-line">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            بستن
          </Button>

          <div className="flex items-center gap-2">
            {/* دکمه ارسال برای بررسی اگر سوال draft باشد */}
            {canAction && isDraft && (
              <Button
                variant="teal"
                onClick={() => onSubmitForReview?.(question)}
                loading={busy}
              >
                ارسال برای بررسی (Under Review)
              </Button>
            )}

            {/* دکمه‌های تأیید و رد برای سوالات تحت بررسی */}
            {canAction && isUnderReview && (
              <>
                <Button
                  variant="outline"
                  className="text-red hover:bg-red/10 border-red/40"
                  onClick={() => onReject?.(question)}
                  disabled={busy}
                >
                  رد سوال و بازگشت به پیش‌نویس ✕
                </Button>
                <Button
                  variant="teal"
                  onClick={() => onApprove?.(question)}
                  loading={busy}
                >
                  تأیید سوال (Approved) ✓
                </Button>
              </>
            )}

            {/* دکمه فعال‌سازی سوال برای آزمون‌ها اگر approved باشد */}
            {canAction && isApproved && (
              <Button
                variant="gold"
                onClick={() => onActivate?.(question)}
                loading={busy}
              >
                فعال‌سازی در آزمون‌ها (Active)
              </Button>
            )}
          </div>
        </div>
      </div>
    </Modal>
  );
}
