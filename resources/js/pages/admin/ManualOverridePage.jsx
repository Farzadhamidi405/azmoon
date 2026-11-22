import { useCallback, useEffect, useState } from 'react';
import { PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';
import formatDate from '../../lib/formatDate.js';

export default function ManualOverridePage() {
  const [sessions, setSessions] = useState([]);
  const [selectedAttemptId, setSelectedAttemptId] = useState('');
  const [selectedAction, setSelectedAction] = useState('extend_time');
  const [reason, setReason] = useState('قطعی برق');
  const [overridesHistory, setOverridesHistory] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  // بارگذاری جلسات آزمون
  useEffect(() => {
    api
      .get(`${API_BASE}/admin/sessions`, { params: { per_page: 50 } })
      .then((res) => {
        const list = unwrapList(res.data).items || [];
        setSessions(list);
        if (list.length > 0) {
          setSelectedAttemptId(String(list[0].id));
        }
      })
      .catch(() => notifyError('خطا در بارگذاری جلسات آزمون.'))
      .finally(() => setLoading(false));
  }, []);

  // بارگذاری تاریخچه Override های این تلاش
  const loadHistory = useCallback(async (attemptId) => {
    if (!attemptId) return;
    try {
      const res = await api.get(`${API_BASE}/admin/attempts/${attemptId}/overrides`);
      const list = res.data?.data || [];
      setOverridesHistory(Array.isArray(list) ? list : []);
    } catch {
      setOverridesHistory([]);
    }
  }, []);

  useEffect(() => {
    if (selectedAttemptId) {
      loadHistory(selectedAttemptId);
    }
  }, [selectedAttemptId, loadHistory]);

  const handleAction = async () => {
    if (!selectedAttemptId) {
      notifyError('لطفاً ابتدا یک جلسه آزمون را انتخاب کنید.');
      return;
    }

    setBusy(true);
    try {
      await api.post(`${API_BASE}/admin/attempts/${selectedAttemptId}/overrides`, {
        action: selectedAction,
        reason,
        old_value: { timestamp: new Date().toISOString() },
        new_value: { applied_action: selectedAction, reason },
      });
      notifySuccess('عملیات Manual Override با موفقیت اعمال و در لاگ ثبت شد.');
      await loadHistory(selectedAttemptId);
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در اعمال عملیات دستی روی جلسه.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  const currentSession = sessions.find((s) => String(s.id) === String(selectedAttemptId));

  return (
    <div className="max-w-[760px] mx-auto w-full space-y-4">
      <PageHeader
        title={`Manual Override — جلسه ${currentSession ? `«${currentSession.candidate?.name || 'متقاضی'}»` : ''}`}
        crumb={['جلسات و لابراتوار', 'Manual Override']}
      />

      <div className="card space-y-4">
        <div className="helper text-[12px] text-ink-soft bg-paper/60 p-2.5 rounded-md border border-line-soft">
          هر عملیات Override نیازمند دسترسی مجاز است و همراه با دلیل و هویت کاربر در Audit Log به صورت غیرقابل تغییر ثبت می‌گردد.
        </div>

        {/* انتخاب جلسه آزمون */}
        <div className="space-y-1">
          <label className="text-xs font-semibold text-ink">انتخاب جلسه / متقاضی آزمون:</label>
          <select
            value={selectedAttemptId}
            onChange={(e) => setSelectedAttemptId(e.target.value)}
            disabled={loading}
            className="w-full rounded border border-line bg-white p-2 text-xs text-ink font-semibold"
          >
            {sessions.map((sess) => (
              <option key={sess.id} value={sess.id}>
                جلسه #{sess.id} — {sess.candidate?.name || 'متقاضی'} ({sess.exam?.title || 'آزمون'}) [سیستم: {sess.computer?.computer_code || 'نامشخص'}]
              </option>
            ))}
            {sessions.length === 0 && <option value="">هیچ جلسه فعالی یافت نشد</option>}
          </select>
        </div>

        <div className="space-y-2 pt-1">
          <div className="text-xs font-semibold text-ink-soft">نوع عملیات جلسه:</div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => setSelectedAction('extend_time')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'extend_time' ? 'btn-primary' : 'btn-outline'}`}
            >
              تمدید زمان
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('reset_session')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'reset_session' ? 'btn-primary' : 'btn-outline'}`}
            >
              Reset Session
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('change_computer')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'change_computer' ? 'btn-primary' : 'btn-outline'}`}
            >
              تغییر کامپیوتر
            </button>
          </div>
          <div className="flex flex-wrap gap-2 pt-1">
            <button
              type="button"
              onClick={() => setSelectedAction('force_submit')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'force_submit' ? 'btn-primary' : 'btn-outline'}`}
            >
              Force Submit
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('reopen')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'reopen' ? 'btn-primary' : 'btn-outline'}`}
            >
              بازگشایی Attempt
            </button>
            <button
              type="button"
              onClick={() => setSelectedAction('invalidate')}
              className={`btn text-xs cursor-pointer ${selectedAction === 'invalidate' ? 'btn-danger' : 'btn-outline text-red'}`}
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
            <label className="text-xs font-semibold text-ink">دلیل عملیات (الزامی جهت ثبت در لاگ امنیتی)</label>
            <select
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              className="w-full rounded border border-line p-2 text-xs bg-white text-ink"
            >
              <option value="قطعی برق">قطعی برق لابراتوار</option>
              <option value="مشکل فنی کامپیوتر">مشکل فنی یا سخت‌افزاری کامپیوتر</option>
              <option value="تخلف یا تقلب محرز">تخلف یا تقلب محرز داوطلب</option>
              <option value="درخواست موجه ناظر">درخواست موجه ناظر جلسه</option>
              <option value="سایر دلایل">سایر دلایل موجه</option>
            </select>
          </div>

          <button
            type="button"
            onClick={handleAction}
            disabled={busy || !selectedAttemptId}
            className={`btn text-xs font-semibold cursor-pointer disabled:opacity-50 ${
              selectedAction === 'invalidate' ? 'btn-danger' : 'btn-primary'
            }`}
          >
            {busy ? <Spinner size="sm" /> : 'تأیید و اعمال عملیات در پایگاه‌داده'}
          </button>
        </div>

        {/* سوابق Override های قبلی این Attempt */}
        {overridesHistory.length > 0 && (
          <div className="border-t border-line-soft pt-3 space-y-2">
            <div className="font-bold text-navy text-xs">سوابق عملیات دستی ثبت‌شده برای این جلسه:</div>
            <div className="space-y-1.5 text-xs text-ink-soft">
              {overridesHistory.map((ov, idx) => (
                <div key={ov.id || idx} className="p-2 rounded bg-paper/50 border border-line-soft flex items-center justify-between">
                  <div>
                    <span className="font-semibold text-navy">{ov.action}</span>: {ov.reason}
                    <span className="text-[11px] text-ink-soft block">توسط: {ov.performed_by?.name || 'مدیر سیستم'}</span>
                  </div>
                  <span className="text-[11px] font-mono">{ov.performed_at ? formatDate(ov.performed_at) : '—'}</span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

export { ManualOverridePage };
