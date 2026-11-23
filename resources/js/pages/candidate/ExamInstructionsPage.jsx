import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Badge, Button, Spinner } from '../../components/ui/index.js';
import { useAuth } from '../../context/AuthContext.jsx';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError } from '../../lib/notify.js';

export default function ExamInstructionsPage() {
  const { user } = useAuth();
  const navigate = useNavigate();

  const [dashboardData, setDashboardData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [agreed, setAgreed] = useState(false);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    let active = true;
    api
      .get(`${API_BASE}/auth/candidate/dashboard`)
      .then((res) => {
        if (active) setDashboardData(res.data?.data || null);
      })
      .catch(() => {
        // نادیده گرفتن خطا
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  const handleStart = async () => {
    if (!agreed || !readiness?.can_start) return;
    setStarting(true);
    try {
      // ارسال درخواست واقعی به بک‌اند برای اعتبارسنجی تمام شروط سروری و ایجاد یا بازیابی Attempt
      await api.post(`${API_BASE}/candidate/attempts/start`);

      if (document.documentElement.requestFullscreen) {
        try {
          await document.documentElement.requestFullscreen();
        } catch {
          // نادیده گرفتن خطا در صورت عدم پشتیبانی یا رد دسترسی
        }
      }
      navigate('/candidate/exam');
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'امکان شروع آزمون وجود ندارد.';
      notifyError(msg);
    } finally {
      setStarting(false);
    }
  };

  const assignment = dashboardData?.assignment;
  const exam = assignment?.exam;
  const readiness = dashboardData?.readiness || {
    can_start: false,
    label: 'در حال استعلام',
    message: 'وضعیت مجوز ورود به آزمون در حال بررسی است.',
    tone: 'pending',
  };

  return (
    <div dir="rtl" className="min-h-screen bg-canvas p-4 md:p-8 flex items-center justify-center font-vazir text-xs">
      <div className="w-full max-w-2xl bg-panel border-2 border-line rounded-3xl shadow-md p-6 md:p-8 space-y-6">
        {/* Header */}
        <div className="text-center space-y-2 border-b border-line pb-4">
          <div className="inline-block px-3 py-1 bg-gold/15 text-navy font-black text-xs rounded-full">
            پوهنتون کندز — معاونیت امور علمی
          </div>
          <h1 className="text-xl md:text-2xl font-black text-navy">
            دستورالعمل و ضوابط آزمون آنلاین
          </h1>
          <p className="text-xs text-ink-soft">
            متقاضی محترم: <b className="text-navy">{user?.name || 'داوطلب گرامی'}</b> (کد داوطلبی: {user?.candidate_code || user?.username})
          </p>
        </div>

        {/* مشخصات آزمون تخصیص‌یافته */}
        {loading ? (
          <div className="p-4 card text-center text-ink-soft flex items-center justify-center gap-2">
            <Spinner size="sm" />
            <span>در حال واکشی اطلاعات آزمون و وضعیت دسترسی...</span>
          </div>
        ) : exam ? (
          <div className="card p-4 bg-paper/60 border border-line rounded-2xl space-y-2">
            <div className="flex items-center justify-between border-b border-line/60 pb-1.5">
              <span className="font-extrabold text-navy text-sm">{exam.title}</span>
              <Badge tone="teal">تخصیص‌یافته</Badge>
            </div>
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
              <div>
                <span className="text-ink-soft">مدت زمان: </span>
                <b className="font-mono text-navy font-bold">{exam.duration_minutes} دقیقه</b>
              </div>
              <div>
                <span className="text-ink-soft">نمره کل: </span>
                <b className="font-mono text-ink font-semibold">{exam.total_marks} نمره</b>
              </div>
              <div>
                <span className="text-ink-soft">صندلی / سیستم: </span>
                <b className="font-mono text-teal font-bold">{assignment?.computer?.computer_code || 'معین'}</b>
              </div>
            </div>
          </div>
        ) : null}

        {/* جعبه بررسی صلاحیت و وضعیت سروری */}
        {!loading && (
          <div
            className={`p-3.5 rounded-2xl border text-xs leading-relaxed flex items-start gap-2.5 ${
              readiness.can_start
                ? 'bg-teal/10 border-teal/30 text-teal-900'
                : 'bg-red-50 border-red-300 text-red-900'
            }`}
          >
            <span className="text-base shrink-0">{readiness.can_start ? '✓' : '⚠️'}</span>
            <div className="space-y-1">
              <div className="font-bold">
                {readiness.can_start ? 'مجوز شروع آزمون صادر شده است:' : 'شما در حال حاضر مجاز به شروع آزمون نیستید:'}
              </div>
              <div>{readiness.message}</div>
            </div>
          </div>
        )}

        {/* Instructions list */}
        <div className="space-y-3 text-xs md:text-sm text-ink leading-relaxed">
          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۱
            </span>
            <div>
              <b>محاسبه زمان سروری:</b> تایمر آزمون مستقیماً از زمان سرور محاسبه شده و زمان انقضا در آغاز آزمون ثبت می‌گردد؛ زمان در هنگام قطع موقت اینترنت ذخیره می‌ماند.
            </div>
          </div>

          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۲
            </span>
            <div>
              <b>پایش رفتار و ضدتقلب مرورگر:</b> خروج از حالت تمام‌صفحه یا سوئیچ به برنامه‌های دیگر ثبت و به ناظر گزارش می‌شود.
            </div>
          </div>

          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۳
            </span>
            <div>
              <b>ذخیره‌سازی هوشمند پاسخ‌ها:</b> پاسخ‌های شما بلافاصله پس از انتخاب ثبت می‌شوند. در صورت بروز هرگونه قطعی برق یا سیستم، آزمون از آخرین وضعیت قابل بازیابی خواهد بود.
            </div>
          </div>
        </div>

        {/* Agreement checkbox */}
        <div
          className={`p-4 rounded-xl border flex items-center gap-3 select-none ${
            readiness.can_start
              ? 'bg-paper/70 border-line cursor-pointer'
              : 'bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed'
          }`}
          onClick={() => {
            if (readiness.can_start) setAgreed(!agreed);
          }}
        >
          <input
            type="checkbox"
            checked={agreed}
            disabled={!readiness.can_start}
            onChange={(e) => {
              if (readiness.can_start) setAgreed(e.target.checked);
            }}
            className="size-5 rounded text-navy focus:ring-navy cursor-pointer disabled:cursor-not-allowed"
          />
          <span className="text-xs md:text-sm font-semibold text-ink">
            اینجانب کلیه قوانین و مقررات انضباطی آزمون را مطالعه نموده و به آن متعهد می‌باشم.
          </span>
        </div>

        {/* Actions */}
        <div className="flex justify-between items-center pt-2">
          <Link to="/candidate/dashboard">
            <Button variant="outline">
              ← بازگشت به داشبورد
            </Button>
          </Link>
          <Button
            variant="gold"
            size="lg"
            disabled={!agreed || !readiness.can_start}
            loading={starting}
            onClick={handleStart}
          >
            شروع آزمون 🚀
          </Button>
        </div>
      </div>
    </div>
  );
}
