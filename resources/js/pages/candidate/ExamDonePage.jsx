import { useNavigate } from 'react-router-dom';
import { Button } from '../../components/ui/index.js';
import { useAuth } from '../../context/AuthContext.jsx';

export default function ExamDonePage() {
  const navigate = useNavigate();
  const { logout, user } = useAuth();

  const handleLogout = async () => {
    try {
      await logout();
    } catch {
      // ignore
    }
    navigate('/login');
  };

  return (
    <div dir="rtl" className="min-h-screen bg-canvas font-vazir flex items-center justify-center p-4">
      <div className="card max-w-lg w-full p-8 text-center space-y-6 shadow-md border border-line">
        <div className="size-20 mx-auto rounded-full bg-teal-soft flex items-center justify-center text-3xl text-teal">
          ✓
        </div>

        <div className="space-y-2">
          <h1 className="text-2xl font-black text-navy">آزمون با موفقیت به پایان رسید</h1>
          <p className="text-xs md:text-sm text-ink-soft leading-relaxed">
            پاسخ‌های شما با موفقیت در پایگاه داده مرکزی پوهنتون کندز ثبت و کد رهگیری آزمون صادر گردید.
          </p>
        </div>

        <div className="p-4 rounded-xl bg-paper/60 border border-line text-xs text-ink space-y-1.5 text-right">
          <div>
            متقاضی: <b className="text-navy">{user?.name || 'داوطلب'}</b>
          </div>
          <div>
            کد داوطلبی: <b className="font-mono text-navy">{user?.candidate_code || user?.username}</b>
          </div>
          <div>
            وضعیت پاسخنامه: <span className="font-bold text-teal">دریافت و منجمد شد (Archived)</span>
          </div>
        </div>

        <div className="text-xs text-ink-soft leading-relaxed">
          نتایج نهایی پس از طی مراحل کنترل کیفیت و تأیید کمیته کنکور پوهنتون کندز از طریق سامانه نتایج اعلام خواهد شد. لطفاً سیستم خود را ترک کرده و مراتب را به ناظر سالن اطلاع دهید.
        </div>

        <div className="pt-2">
          <Button variant="primary" size="lg" className="w-full" onClick={handleLogout}>
            خروج ایمن از حساب کاربری
          </Button>
        </div>
      </div>
    </div>
  );
}
