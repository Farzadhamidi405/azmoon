import { useState } from 'react';
import { Badge, Button, Modal, Spinner } from '../ui/index.js';

const STATUS_MAP = {
  present: { label: 'حاضر (تأیید ورود)', tone: 'teal' },
  absent: { label: 'غایب / شروع‌نکرده', tone: 'bad' },
  late: { label: 'حضور با تأخیر', tone: 'pending' },
  in_progress: { label: 'در حال برگزاری آزمون', tone: 'gold' },
  completed: { label: 'تکمیل‌شده', tone: 'teal' },
  disqualified: { label: 'محروم از آزمون', tone: 'bad' },
  cancelled: { label: 'لغوشده', tone: 'off' },
};

export default function CandidateDetailModal({
  open,
  onClose,
  candidate = null,
  onOpenCard = null,
  onEdit = null,
}) {
  if (!candidate) return null;

  const statusCfg = STATUS_MAP[candidate.status] || { label: candidate.status || 'نامشخص', tone: 'off' };
  const assignment = candidate.latest_assignment || candidate.latestAssignment || (candidate.assignments && candidate.assignments[0]);
  const exam = assignment?.exam;
  const shift = assignment?.shift;
  const lab = assignment?.lab;
  const computer = assignment?.computer;
  const attempts = candidate.attempts || [];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`پرونده متقاضی: ${candidate.name || candidate.candidate_code}`}
      size="lg"
    >
      <div className="space-y-4 text-xs font-vazir">
        {/* هدر کارت اطلاعات هویتی متقاضی */}
        <div className="border border-line rounded-2xl p-4 bg-paper/40 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="size-12 rounded-xl bg-navy text-white flex items-center justify-center font-bold text-lg shadow-sm">
              {candidate.name ? candidate.name.charAt(0) : 'م'}
            </div>
            <div>
              <div className="text-base font-extrabold text-navy">{candidate.name || 'بدون نام'}</div>
              <div className="text-[11px] text-ink-soft flex items-center gap-2 mt-0.5">
                <span>کد متقاضی: <b className="font-mono text-navy font-bold">{candidate.candidate_code}</b></span>
                <span>·</span>
                <span>یوزرنیم: <b className="font-mono text-navy font-bold">{candidate.username}</b></span>
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge tone={statusCfg.tone}>{statusCfg.label}</Badge>
          </div>
        </div>

        {/* مشخصات فردی و پرونده */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div className="card p-3.5 space-y-2">
            <div className="font-bold text-navy text-xs border-b border-line pb-1.5">
              مشخصات ثبت‌نام و شناسایی
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">کد ملی / شماره تذکره:</span>
              <span className="font-mono text-ink font-semibold">{candidate.national_id || 'ثبت نشده'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">تاریخ ثبت در سامانه:</span>
              <span className="text-ink">{candidate.created_at ? new Date(candidate.created_at).toLocaleDateString('fa-IR') : '—'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">زمان Check-in / ورود:</span>
              <span className="text-ink font-mono">{candidate.checked_in_at ? new Date(candidate.checked_in_at).toLocaleTimeString('fa-IR') : 'هنوز ثبت نشده'}</span>
            </div>
            <div className="flex justify-between py-1">
              <span className="text-ink-soft">آخرین ورود به پنل آزمون:</span>
              <span className="text-ink font-mono">{candidate.last_login_at ? new Date(candidate.last_login_at).toLocaleString('fa-IR') : 'تاکنون وارد نشده'}</span>
            </div>
          </div>

          <div className="card p-3.5 space-y-2">
            <div className="font-bold text-navy text-xs border-b border-line pb-1.5">
              تخصیص آزمون و صندلی
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">آزمون متصل:</span>
              <span className="font-bold text-navy">{exam?.title || 'هنوز تخصیص نیافته'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">شیفت امتحانی:</span>
              <span className="text-ink">{shift?.name || '—'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">محل برگزاری (لابراتوار):</span>
              <span className="text-ink">{lab?.name || '—'}</span>
            </div>
            <div className="flex justify-between py-1">
              <span className="text-ink-soft">سیستم / کامپیوتر اختصاصی:</span>
              <span className="font-mono font-bold text-teal">{computer?.computer_code || 'تعیین نشده'}</span>
            </div>
          </div>
        </div>

        {/* سوابق نشست‌های امتحان (Attempts) */}
        <div className="card p-3.5 space-y-2">
          <div className="font-bold text-navy text-xs border-b border-line pb-1.5 flex justify-between items-center">
            <span>سوابق و جلسات آزمون داوطلب ({attempts.length})</span>
            <span className="text-[11px] text-ink-soft font-normal">ثبت خودکار سرور</span>
          </div>

          {attempts.length === 0 ? (
            <div className="py-4 text-center text-ink-soft text-xs">
              تاکنون هیچ نشست آزمونی برای این متقاضی آغاز نشده است.
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-right text-xs">
                <thead>
                  <tr className="border-b border-line text-ink-soft">
                    <th className="p-2">شناسه نشست</th>
                    <th className="p-2">آزمون</th>
                    <th className="p-2">وضعیت</th>
                    <th className="p-2">شروع</th>
                    <th className="p-2">نمره</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {attempts.map((att) => (
                    <tr key={att.id} className="hover:bg-panel-2/50">
                      <td className="p-2 font-mono font-bold text-navy">#{att.id}</td>
                      <td className="p-2 text-ink">{att.exam?.title || `آزمون #${att.exam_id}`}</td>
                      <td className="p-2">
                        <Badge tone={att.status === 'completed' ? 'teal' : att.status === 'in_progress' ? 'gold' : 'bad'}>
                          {att.status}
                        </Badge>
                      </td>
                      <td className="p-2 font-mono text-ink-soft text-[11px]">
                        {att.started_at ? new Date(att.started_at).toLocaleTimeString('fa-IR') : '—'}
                      </td>
                      <td className="p-2 font-mono font-bold text-navy">
                        {att.score ?? '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* دکمه‌های پایانی عملیات */}
        <div className="flex items-center justify-between pt-3 border-t border-line">
          <div className="flex items-center gap-2">
            {onOpenCard && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  onClose();
                  onOpenCard(candidate);
                }}
              >
                🪪 مشاهده و چاپ کارت ورود
              </Button>
            )}
            {onEdit && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => {
                  onClose();
                  onEdit(candidate);
                }}
              >
                ✏️ ویرایش متقاضی
              </Button>
            )}
          </div>
          <Button variant="primary" size="sm" onClick={onClose}>
            بستن
          </Button>
        </div>
      </div>
    </Modal>
  );
}
