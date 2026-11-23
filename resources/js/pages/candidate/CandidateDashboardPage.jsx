import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Badge, Button, Spinner } from '../../components/ui/index.js';
import QRCodeImage from '../../components/ui/QRCodeImage.jsx';
import { useAuth } from '../../context/AuthContext.jsx';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError } from '../../lib/notify.js';

export default function CandidateDashboardPage() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const [dashboardData, setDashboardData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loggingOut, setLoggingOut] = useState(false);

  const fetchDashboard = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/auth/candidate/dashboard`);
      setDashboardData(res.data?.data || null);
    } catch (err) {
      notifyError('خطا در بارگذاری اطلاعات داشبورد متقاضی.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchDashboard();
  }, [fetchDashboard]);

  const handleLogout = async () => {
    setLoggingOut(true);
    try {
      await logout();
      navigate('/login', { replace: true });
    } finally {
      setLoggingOut(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-[60vh] flex flex-col items-center justify-center gap-3 font-vazir text-xs text-ink-soft">
        <Spinner size="lg" />
        <span>در حال بارگذاری پرونده و مشخصات جلسه آزمون...</span>
      </div>
    );
  }

  const candidate = dashboardData?.candidate || user;
  const assignment = dashboardData?.assignment;
  const exam = assignment?.exam;
  const shift = assignment?.shift;
  const lab = assignment?.lab;
  const computer = assignment?.computer;
  const readiness = dashboardData?.readiness || {
    code: 'pending_checkin',
    label: 'در حال بررسی',
    tone: 'pending',
    can_start: false,
    message: 'وضعیت آمادگی شما در حال استعلام است.',
  };

  const isCheckedIn = candidate?.is_checked_in || candidate?.status === 'present';
  const isDisqualified = candidate?.status === 'disqualified';

  return (
    <div className="w-full max-w-4xl mx-auto space-y-6 font-vazir text-xs" dir="rtl">
      {/* هدر بالایی با خوش‌آمدگویی و مشخصات */}
      <div className="card p-5 border-2 border-navy/20 bg-panel shadow-sm flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <div className="size-14 rounded-2xl bg-navy text-white flex items-center justify-center font-black text-xl shadow-sm shrink-0">
            {candidate?.name ? candidate.name.charAt(0) : 'م'}
          </div>
          <div>
            <div className="text-[11px] text-ink-soft">پورتال مستقل متقاضی — پوهنتون کندز</div>
            <div className="text-lg font-black text-navy mt-0.5">{candidate?.name || 'متقاضی محترم'}</div>
            <div className="text-[11px] text-ink-soft font-mono flex items-center gap-2 mt-1">
              <span>کد داوطلبی: <b className="text-navy">{candidate?.candidate_code}</b></span>
              <span>·</span>
              <span>نام کاربری: <b className="text-navy">{candidate?.username}</b></span>
              {candidate?.national_id && (
                <>
                  <span>·</span>
                  <span>تذکره: <b>{candidate.national_id}</b></span>
                </>
              )}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2 self-end sm:self-auto">
          <Button variant="outline" size="sm" onClick={fetchDashboard} disabled={loading}>
            ⟳ بازخوانی وضعیت
          </Button>
          <Button variant="outline" size="sm" onClick={handleLogout} loading={loggingOut}>
            خروج از حساب
          </Button>
        </div>
      </div>

      {/* کارت وضعیت آمادگی امتحان (Exam Readiness Status) */}
      <div
        className={`card p-5 border-2 rounded-2xl space-y-3 ${
          readiness.code === 'ready'
            ? 'border-teal/50 bg-teal/5'
            : readiness.code === 'disqualified'
            ? 'border-red/50 bg-red/5'
            : readiness.code === 'in_progress'
            ? 'border-gold/50 bg-gold/5'
            : readiness.code === 'completed'
            ? 'border-teal/30 bg-teal/5'
            : 'border-amber/40 bg-amber/5'
        }`}
      >
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-line/50 pb-2.5">
          <div className="flex items-center gap-2">
            <span className="text-lg">
              {readiness.code === 'ready'
                ? '🟢'
                : readiness.code === 'disqualified'
                ? '🔴'
                : readiness.code === 'in_progress'
                ? '🟡'
                : readiness.code === 'completed'
                ? '✅'
                : '⏳'}
            </span>
            <div className="font-black text-sm text-navy">
              وضعیت دسترسی و آمادگی آزمون: <span className="underline decoration-gold">{readiness.label}</span>
            </div>
          </div>
          <div>
            <Badge tone={readiness.tone}>{readiness.label}</Badge>
          </div>
        </div>

        <p className="text-ink text-[12.5px] leading-relaxed">{readiness.message}</p>

        {/* دکمه اقدام شروع آزمون بر اساس تاییدیه قطعی سرور */}
        <div className="pt-2 flex items-center justify-between flex-wrap gap-2">
          <div className="text-[11px] text-ink-soft">
            {readiness.can_start
              ? '✓ شما واجد شرایط ورود به آزمون هستید.'
              : '⚠️ دکمه ورود تا زمان احراز کامل شرایط غیرفعال است.'}
          </div>

          {readiness.can_start ? (
            <Link to="/candidate/instructions">
              <Button variant="gold" size="md" className="font-bold shadow-sm">
                {readiness.code === 'in_progress' ? 'بازگشت به محیط آزمون جاری ⚡' : 'ورود به دستورالعمل و شروع آزمون →'}
              </Button>
            </Link>
          ) : (
            <Button variant="outline" size="md" disabled className="opacity-60 cursor-not-allowed">
              ورود به آزمون (غیرفعال)
            </Button>
          )}
        </div>
      </div>

      {/* اطلاعات تخصیص، صندلی و زمان‌بندی */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* کارت مشخصات آزمون */}
        <div className="card p-5 space-y-3">
          <div className="font-bold text-navy text-sm border-b border-line pb-2 flex items-center justify-between">
            <span>📚 مشخصات آزمون تخصیص‌یافته</span>
            {exam ? <Badge tone="teal">تخصیص‌یافته</Badge> : <Badge tone="off">نامشخص</Badge>}
          </div>

          {exam ? (
            <div className="space-y-2 text-xs leading-relaxed">
              <div className="flex justify-between py-1 border-b border-line/50">
                <span className="text-ink-soft">عنوان آزمون:</span>
                <span className="font-black text-navy">{exam.title}</span>
              </div>
              <div className="flex justify-between py-1 border-b border-line/50">
                <span className="text-ink-soft">مدت زمان آزمون:</span>
                <span className="font-bold text-navy font-mono">{exam.duration_minutes} دقیقه</span>
              </div>
              <div className="flex justify-between py-1 border-b border-line/50">
                <span className="text-ink-soft">نمره کل آزمون:</span>
                <span className="font-mono text-ink font-semibold">{exam.total_marks} نمره</span>
              </div>
              <div className="flex justify-between py-1">
                <span className="text-ink-soft">حد نصاب قبولی:</span>
                <span className="font-mono text-teal font-bold">{exam.pass_marks} نمره</span>
              </div>
            </div>
          ) : (
            <div className="py-6 text-center text-ink-soft">
              هنوز هیچ آزمونی برای شما تخصیص داده نشده است.
            </div>
          )}
        </div>

        {/* کارت محل برگزاری، شیفت و صندلی */}
        <div className="card p-5 space-y-3">
          <div className="font-bold text-navy text-sm border-b border-line pb-2 flex items-center justify-between">
            <span>🏢 مشخصات استقرار و صندلی</span>
            {computer ? <Badge tone="teal">صندلی معین</Badge> : <Badge tone="pending">تعیین در محل</Badge>}
          </div>

          <div className="space-y-2 text-xs leading-relaxed">
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">شیفت امتحانی:</span>
              <span className="font-bold text-navy">{shift?.name || 'شیفت تعیین‌نشده'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50">
              <span className="text-ink-soft">محل برگزاری (لابراتوار):</span>
              <span className="text-ink font-semibold">{lab?.name || 'لابراتوار مرکزی'}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/50 items-center">
              <span className="text-ink-soft">شماره سیستم / کامپیوتر:</span>
              <span className="font-mono font-black text-sm text-teal bg-teal/10 px-2.5 py-0.5 rounded border border-teal/20">
                {computer?.computer_code || 'در باجه ورودی تعیین می‌شود'}
              </span>
            </div>
            <div className="flex justify-between py-1 items-center">
              <span className="text-ink-soft">وضعیت Check-in حضور:</span>
              <span className={isCheckedIn ? 'text-teal font-bold' : 'text-amber font-bold'}>
                {isCheckedIn ? '✓ حضور تأیید شده' : '⏳ در انتظار ثبت حضور'}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* اطلاعات راهنما برای روز آزمون */}
      <div className="card p-4 bg-paper/60 border border-line space-y-2 text-[11.5px] leading-relaxed text-ink-soft">
        <div className="font-bold text-navy text-xs flex items-center gap-1.5">
          <span>💡</span>
          <span>نکات انضباطی ورود به جلسه آزمون:</span>
        </div>
        <ul className="list-disc list-inside space-y-1">
          <li>حداقل ۱۵ دقیقه قبل از ساعت شروع شیفت در سالن حضور داشته و کارت شناسایی را به باجه Check-in ارائه دهید.</li>
          <li>صرفاً بر روی سیستم و صندلی اختصاص‌یافته مستقر شوید؛ ورود به آزمون از سایر سیستم‌ها مسدود می‌باشد.</li>
          <li>در صورت بروز هرگونه مشکل شبکه، آزمون متوقف نشده و پاسخ‌های ثبت‌شده شما تا لحظه آخر ایمن خواهند بود.</li>
        </ul>
      </div>
    </div>
  );
}
