import { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { Button, Spinner } from '../../components/ui/index.js';
import { useAuth } from '../../context/AuthContext.jsx';
import api from '../../lib/api.js';

export default function ExamDonePage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { logout, user } = useAuth();

  const [result, setResult] = useState(location.state?.result || null);
  const [loading, setLoading] = useState(false);
  const attemptId = location.state?.attemptId || result?.attempt_id;

  useEffect(() => {
    if (result || !attemptId) return;

    let isMounted = true;
    setLoading(true);

    api
      .get(`/api/v1/attempts/${attemptId}/result`)
      .then((res) => {
        if (isMounted && res.data?.success && res.data?.data) {
          setResult(res.data.data);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (isMounted) setLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, [attemptId, result]);

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
      // ignore
    }
    navigate('/login');
  };

  const showImmediate = Boolean(result?.show_result_immediately);
  const isPassed = result?.pass_fail === 'pass';

  return (
    <div dir="rtl" className="min-h-screen bg-canvas font-vazir flex items-center justify-center p-4">
      <div className="card max-w-xl w-full p-6 md:p-8 text-center space-y-6 shadow-md border border-line">
        <div className="size-20 mx-auto rounded-full bg-teal-soft flex items-center justify-center text-3xl text-teal">
          ✓
        </div>

        <div className="space-y-1">
          <h1 className="text-2xl font-black text-navy">آزمون با موفقیت به پایان رسید</h1>
          <p className="text-xs md:text-sm text-ink-soft leading-relaxed">
            پاسخ‌های شما با موفقیت در پایگاه داده مرکزی پوهنتون کندز ثبت و پاسخنامه منجمد گردید.
          </p>
        </div>

        {/* مشخصات متقاضی */}
        <div className="p-4 rounded-xl bg-paper/60 border border-line text-xs text-ink space-y-1.5 text-right">
          <div>
            متقاضی: <b className="text-navy">{user?.name || 'داوطلب'}</b>
          </div>
          <div>
            کد داوطلبی: <b className="font-mono text-navy">{user?.candidate_code || user?.username}</b>
          </div>
          {result?.exam_title && (
            <div>
              عنوان آزمون: <span className="font-bold text-navy">{result.exam_title}</span>
            </div>
          )}
          <div>
            وضعیت پاسخنامه: <span className="font-bold text-teal">دریافت و منجمد شد (Archived)</span>
          </div>
        </div>

        {loading && (
          <div className="py-4 flex items-center justify-center gap-2 text-xs text-ink-soft">
            <Spinner size="sm" />
            <span>در حال واکشی اطلاعات کارنامه...</span>
          </div>
        )}

        {/* نمایش کارنامه فوری در صورت مجاز بودن */}
        {result && showImmediate && (
          <div className="space-y-4 text-right pt-2 border-t border-line">
            <div className="flex items-center justify-between">
              <span className="font-black text-navy text-sm">کارنامه اولیه آزمون</span>
              <span
                className={`px-3 py-1 rounded-full text-xs font-black ${
                  result?.pass_fail === 'pending_manual_grading'
                    ? 'bg-gold/15 text-gold border border-gold/30'
                    : isPassed
                    ? 'bg-teal-soft text-teal border border-teal/20'
                    : 'bg-red/10 text-red border border-red/20'
                }`}
              >
                {result?.pass_fail === 'pending_manual_grading'
                  ? 'در انتظار تصحیح تشریحی'
                  : isPassed
                  ? 'قبول (Passed)'
                  : 'ناموفق (Failed)'}
              </span>
            </div>

            {/* آمار کل نمره و درصد */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5 text-center">
              <div className="p-3 bg-paper rounded-xl border border-line">
                <div className="text-[11px] text-ink-soft">نمره کل</div>
                <div className="text-base font-black text-navy mt-1">
                  {result.total_score ?? 0}
                  {result.total_marks ? (
                    <span className="text-xs text-ink-soft font-normal"> / {result.total_marks}</span>
                  ) : null}
                </div>
              </div>

              <div className="p-3 bg-paper rounded-xl border border-line">
                <div className="text-[11px] text-ink-soft">درصد کل</div>
                <div className="text-base font-black text-navy mt-1">
                  {result.percentage ?? 0}%
                </div>
              </div>

              <div className="p-3 bg-paper rounded-xl border border-line">
                <div className="text-[11px] text-teal">پاسخ‌های صحیح</div>
                <div className="text-base font-black text-teal mt-1">
                  {result.correct_count ?? 0}
                </div>
              </div>

              <div className="p-3 bg-paper rounded-xl border border-line">
                <div className="text-[11px] text-red">پاسخ‌های اشتباه</div>
                <div className="text-base font-black text-red mt-1">
                  {result.wrong_count ?? 0}
                </div>
              </div>
            </div>

            {/* ریزنمرات به تفکیک بخش‌ها */}
            {result.section_results && result.section_results.length > 0 && (
              <div className="space-y-2 pt-2">
                <div className="text-xs font-bold text-navy">ریزنمرات به تفکیک بخش‌های آزمون:</div>
                <div className="overflow-x-auto border border-line rounded-xl">
                  <table className="w-full text-xs text-right">
                    <thead className="bg-paper text-ink-soft font-medium border-b border-line">
                      <tr>
                        <th className="p-2.5">نام بخش</th>
                        <th className="p-2.5 text-center">نمره</th>
                        <th className="p-2.5 text-center text-teal">صحیح</th>
                        <th className="p-2.5 text-center text-red">غلط</th>
                        <th className="p-2.5 text-center text-ink-soft">بی‌پاسخ</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {result.section_results.map((sr) => (
                        <tr key={sr.section_id} className="hover:bg-paper/50">
                          <td className="p-2.5 font-bold text-navy">{sr.section_name}</td>
                          <td className="p-2.5 text-center font-bold">{sr.score}</td>
                          <td className="p-2.5 text-center text-teal font-bold">{sr.correct_count}</td>
                          <td className="p-2.5 text-center text-red font-bold">{sr.wrong_count}</td>
                          <td className="p-2.5 text-center text-ink-soft">{sr.unanswered_count}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* اعلان سوالات تشریحی نیازمند تصحیح دستی */}
            {result.has_pending_manual && (
              <div className="p-3 rounded-xl bg-gold/10 border border-gold/30 text-xs text-ink leading-relaxed">
                ⚠️ <span className="font-bold text-navy">توجه:</span> این آزمون شامل سوالات تشریحی است. نمرات نهایی این بخش‌ها پس از بازبینی و ارزیابی توسط مصححان به کارنامه افزوده خواهد شد.
              </div>
            )}
          </div>
        )}

        {(!result || !showImmediate) && (
          <div className="text-xs text-ink-soft leading-relaxed pt-2 border-t border-line">
            نتایج نهایی پس از طی مراحل کنترل کیفیت و تأیید کمیته کنکور پوهنتون کندز از طریق سامانه نتایج اعلام خواهد شد. لطفاً سیستم خود را ترک کرده و مراتب را به ناظر سالن اطلاع دهید.
          </div>
        )}

        <div className="pt-2">
          <Button variant="primary" size="lg" className="w-full" onClick={handleLogout}>
            خروج ایمن از حساب کاربری
          </Button>
        </div>
      </div>
    </div>
  );
}
