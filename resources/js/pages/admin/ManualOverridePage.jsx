import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

export default function ManualOverridePage() {
  const [selectedAction, setSelectedAction] = useState('extend_time');
  const [reason, setReason] = useState('قطعی برق');
  const [confirmed, setConfirmed] = useState(false);

  const handleAction = () => {
    setConfirmed(true);
    setTimeout(() => setConfirmed(false), 3000);
  };

  return (
    <div className="max-w-[600px] space-y-4">
      <PageHeader
        title="Manual Override — Attempt #4471"
        crumb={['جلسات و لابراتوار', 'Manual Override']}
      />

      <div className="card space-y-4">
        <div className="helper text-[12px] text-ink-soft bg-paper/60 p-2.5 rounded-md border border-line-soft">
          هر عملیات Override نیازمند Permission است و همراه با دلیل در Audit Log ثبت می‌شود.
        </div>

        <div className="space-y-2">
          <div className="text-xs font-semibold text-ink-soft">عملیات جلسه:</div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => setSelectedAction('extend_time')}
              className={`btn text-xs ${selectedAction === 'extend_time' ? 'btn-primary' : 'btn-outline'}`}
            >
              تمدید زمان
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('reset_session')}
              className={`btn text-xs ${selectedAction === 'reset_session' ? 'btn-primary' : 'btn-outline'}`}
            >
              Reset Session
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('change_computer')}
              className={`btn text-xs ${selectedAction === 'change_computer' ? 'btn-primary' : 'btn-outline'}`}
            >
              تغییر کامپیوتر
            </button>
          </div>
          <div className="flex flex-wrap gap-2 pt-1">
            <button
              type="button"
              onClick={() => setSelectedAction('force_submit')}
              className={`btn text-xs ${selectedAction === 'force_submit' ? 'btn-primary' : 'btn-outline'}`}
            >
              Force Submit
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('reopen')}
              className={`btn text-xs ${selectedAction === 'reopen' ? 'btn-primary' : 'btn-outline'}`}
            >
              بازگشایی Attempt
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('invalidate')}
              className={`btn text-xs ${selectedAction === 'invalidate' ? 'btn-danger' : 'btn-outline text-red'}`}
            >
              باطل‌کردن Attempt
            </button>
          </div>
        </div>

        <div className="border-t border-line-soft pt-3 space-y-3">
          <div className="section-title text-[14px] font-bold text-navy">
            {selectedAction === 'extend_time' && 'تمدید زمان +۱۰ دقیقه'}
            {selectedAction === 'reset_session' && 'بازنشانی سشن متقاضی (Reset Session)'}
            {selectedAction === 'change_computer' && 'تغییر کامپیوتر و انتقال متقاضی'}
            {selectedAction === 'force_submit' && 'ثبت و ارسال اجباری پاسخ‌ها (Force Submit)'}
            {selectedAction === 'reopen' && 'بازگشایی مجدد آزمون (Reopen Attempt)'}
            {selectedAction === 'invalidate' && 'باطل‌کردن کامل آزمون (Invalidate Attempt)'}
          </div>

          <div className="field space-y-1">
            <label className="text-xs font-semibold text-ink">دلیل عملیات</label>
            <select
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              className="w-full rounded border border-line p-2 text-xs bg-white text-ink"
            >
              <option>قطعی برق</option>
              <option>مشکل فنی کامپیوتر</option>
              <option>تخلف یا تقلب محرز</option>
              <option>درخواست موجه ناظر</option>
              <option>سایر دلایل</option>
            </select>
          </div>

          <button
            type="button"
            onClick={handleAction}
            className={`btn text-xs font-semibold cursor-pointer ${
              selectedAction === 'invalidate' ? 'btn-danger' : 'btn-primary'
            }`}
          >
            {confirmed ? '✓ عملیات با موفقیت اعمال شد' : 'تأیید و اعمال عملیات'}
          </button>
        </div>
      </div>
    </div>
  );
}

export { ManualOverridePage };
