import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '../../components/ui/index.js';
import { useAuth } from '../../context/AuthContext.jsx';

export default function ExamInstructionsPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [agreed, setAgreed] = useState(false);
  const [starting, setStarting] = useState(false);

  const handleStart = async () => {
    if (!agreed) return;
    setStarting(true);
    try {
      // Enter fullscreen if supported
      if (document.documentElement.requestFullscreen) {
        try {
          await document.documentElement.requestFullscreen();
        } catch {
          // ignore
        }
      }
      navigate('/candidate/exam');
    } finally {
      setStarting(false);
    }
  };

  return (
    <div dir="rtl" className="min-h-screen bg-canvas p-4 md:p-8 flex items-center justify-center font-vazir">
      <div className="w-full max-w-2xl bg-panel border border-line rounded-2xl shadow-md p-6 md:p-8 space-y-6">
        {/* Header */}
        <div className="text-center space-y-2 border-b border-line pb-4">
          <div className="inline-block px-3 py-1 bg-gold/15 text-navy font-black text-xs rounded-full">
            پوهنتون کندز — معاونیت امور علمی
          </div>
          <h1 className="text-xl md:text-2xl font-black text-navy">
            دستورالعمل و ضوابط آزمون آنلاین
          </h1>
          <p className="text-xs text-ink-soft">
            متقاضی محترم: <b className="text-navy">{user?.name || 'داوطلب گرامی'}</b> (کد: {user?.candidate_code || user?.username})
          </p>
        </div>

        {/* Instructions list */}
        <div className="space-y-3 text-xs md:text-sm text-ink leading-relaxed">
          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۱
            </span>
            <div>
              <b>مدت زمان آزمون:</b> زمان آزمون از لحظه فشردن دکمه «شروع آزمون» آغاز شده و امکان توقف یا تمدید خودسرانه وجود ندارد.
            </div>
          </div>

          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۲
            </span>
            <div>
              <b>پایش رفتار و ضدتقلب مرورگر:</b> خروج از حالت تمام‌صفحه، سوئیچ به برنامه‌های دیگر یا کلیدهای میانبر به عنوان رویداد ضدتقلب ثبت و به ناظر گزارش می‌شود. (توجه: برای قفل سخت‌افزاری کامل سیستم، نیاز به مرورگر اختصاصی کیوسک پوهنتون کندز است؛ در مرورگر وب استاندارد، پایش نرم‌افزاری فعال است).
            </div>
          </div>

          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۳
            </span>
            <div>
              <b>ذخیره‌سازی هوشمند پاسخ‌ها:</b> پاسخ‌های شما بلافاصله پس از انتخاب ثبت می‌شوند. در صورت بروز هرگونه قطعی برق یا شبکه، با مراجعه به ناظر جلسه، آزمون شما دقیقاً از ثانیه قطع‌شده ادامه خواهد یافت.
            </div>
          </div>

          <div className="flex items-start gap-2.5">
            <span className="size-6 shrink-0 rounded-full bg-navy text-panel flex items-center justify-center text-xs font-bold">
              ۴
            </span>
            <div>
              <b>پایان آزمون:</b> پس از پاسخ به تمام سوالات، روی دکمه «تحویل و پایان آزمون» کلیک کنید. با اتمام مهلت زمان، آزمون به‌طور خودکار تحویل می‌گردد.
            </div>
          </div>
        </div>

        {/* Agreement checkbox */}
        <div className="p-4 rounded-xl bg-paper/70 border border-line flex items-center gap-3 cursor-pointer select-none" onClick={() => setAgreed(!agreed)}>
          <input
            type="checkbox"
            checked={agreed}
            onChange={(e) => setAgreed(e.target.checked)}
            className="size-5 rounded text-navy focus:ring-navy cursor-pointer"
          />
          <span className="text-xs md:text-sm font-semibold text-ink">
            اینجانب کلیه قوانین و مقررات انضباطی آزمون را مطالعه نموده و به آن متعهد می‌باشم.
          </span>
        </div>

        {/* Actions */}
        <div className="flex justify-between items-center pt-2">
          <Button variant="outline" onClick={() => navigate('/login')}>
            خروج از حساب
          </Button>
          <Button
            variant="gold"
            size="lg"
            disabled={!agreed}
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
