import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button, PageHeader } from '../../components/ui/index.js';
import useDashboardData from '../../hooks/useDashboardData.js';
import { useAuth } from '../../hooks/useAuth.js';

const TEXT = Object.freeze({
  title: 'داشبورد مدیریت',
  crumbRoot: 'پوهنتون کندز',
  crumbPage: 'داشبورد',
  refresh: 'تازه‌سازی',
});

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, reload, busy } = useDashboardData(user);
  const { published, candidates, inProgress } = data;

  const [activeSessions] = useState([
    { name: 'احمد ولی', exam: 'Batch A', q: '۲۱ / ۵۰', rem: '۳۸:۱۲', status: 'active', label: 'در حال امتحان' },
    { name: 'مریم رضایی', exam: 'Batch A', q: '۷ / ۵۰', rem: '۵۲:۴۰', status: 'active', label: 'در حال امتحان' },
    { name: 'سمیع الله', exam: 'Batch B', q: '—', rem: '—', status: 'disconnected', label: 'قطع اتصال' },
  ]);

  const examsCount = published?.status === 'ok' ? published.value : '۳';
  const candidatesCount = candidates?.status === 'ok' ? candidates.value : '۱۲۴';
  const inProgressCount = inProgress?.status === 'ok' ? inProgress.value : '۱۸';

  return (
    <div className="space-y-5">
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={
          <Button variant="outline" size="sm" loading={busy} onClick={reload}>
            {TEXT.refresh}
          </Button>
        }
      />

      {/* ۴ کارت آمار مطابق نمونه مرجع Prototype با قابلیت کلیک و انتقال به صفحات مربوطه */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <Link
          to="/admin/exams"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--gold)' }}
        >
          <div className="num">{examsCount}</div>
          <div className="lbl">آزمون فعال</div>
        </Link>
        <Link
          to="/admin/candidates"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--navy-3)' }}
        >
          <div className="num">{candidatesCount}</div>
          <div className="lbl">متقاضی ثبت‌شده</div>
        </Link>
        <Link
          to="/admin/sessions"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--teal)' }}
        >
          <div className="num">{inProgressCount}</div>
          <div className="lbl">در حال امتحان دادن</div>
        </Link>
        <Link
          to="/admin/statistics"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--amber)' }}
        >
          <div className="num">۹۶٪</div>
          <div className="lbl">نرخ تکمیل امتحان</div>
        </Link>
      </div>

      {/* بخش پایینی: جلسات فعال + فعالیت‌های اخیر + سلامت سیستم */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
        {/* جدول جلسات فعال اکنون */}
        <div className="card lg:col-span-6 overflow-x-auto">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <Link to="/admin/sessions" className="hover:text-gold transition-colors flex items-center gap-1.5">
              <span>جلسات فعال اکنون</span>
              <span className="text-xs text-ink-soft font-normal">←</span>
            </Link>
            <Link to="/admin/sessions">
              <span className="badge on text-[10.5px] cursor-pointer hover:opacity-90">۳ جلسه در جریان</span>
            </Link>
          </div>
          <table className="w-full text-right text-xs">
            <thead>
              <tr className="border-b border-line text-ink-soft">
                <th className="py-2.5 px-3">متقاضی</th>
                <th className="py-2.5 px-3">آزمون</th>
                <th className="py-2.5 px-3">سوال</th>
                <th className="py-2.5 px-3">باقیمانده</th>
                <th className="py-2.5 px-3">وضعیت</th>
              </tr>
            </thead>
            <tbody>
              {activeSessions.map((sess, idx) => (
                <tr key={idx} className="border-b border-line-soft hover:bg-row-hover">
                  <td className="py-2.5 px-3 font-semibold text-ink">
                    <Link to="/admin/candidates" className="hover:text-navy hover:underline">
                      {sess.name}
                    </Link>
                  </td>
                  <td className="py-2.5 px-3 text-ink-soft">
                    <Link to="/admin/exams" className="hover:text-navy hover:underline">
                      {sess.exam}
                    </Link>
                  </td>
                  <td className="py-2.5 px-3">{sess.q}</td>
                  <td className="py-2.5 px-3 font-mono font-bold text-navy">{sess.rem}</td>
                  <td className="py-2.5 px-3">
                    <Link to="/admin/sessions">
                      <span className={sess.status === 'active' ? 'badge on cursor-pointer' : 'badge bad cursor-pointer'}>
                        {sess.label}
                      </span>
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* کارت فعالیت‌های اخیر */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <span>فعالیت‌های اخیر</span>
            <Link to="/admin/audit" className="text-xs text-gold hover:underline">
              مشاهده لاگ‌ها
            </Link>
          </div>
          <div className="text-[12.5px] leading-[2.2] text-ink-soft">
            <div>
              <Link to="/admin/candidates" className="hover:text-navy hover:underline">
                • افزوده‌شدن ۲۰ متقاضی جدید
              </Link>
            </div>
            <div>
              <Link to="/admin/exams" className="hover:text-navy hover:underline">
                • آزمون «Batch B» منتشر شد
              </Link>
            </div>
            <div>
              <Link to="/admin/questions" className="hover:text-navy hover:underline">
                • ۶ سوال گرامر اضافه شد
              </Link>
            </div>
            <div>
              <Link to="/admin/candidates/cards" className="hover:text-navy hover:underline">
                • کارت‌های ورود چاپ شد
              </Link>
            </div>
          </div>
        </div>

        {/* کارت System Health */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <span>System Health</span>
            <Link to="/admin/settings" className="text-xs text-gold hover:underline">
              تنظیمات
            </Link>
          </div>
          <div className="space-y-1">
            <div className="health-row">
              <span className="flex items-center">
                <span className="health-dot"></span>API
              </span>
              <span className="badge on">Online</span>
            </div>
            <div className="health-row">
              <span className="flex items-center">
                <span className="health-dot"></span>Database
              </span>
              <span className="badge on">Online</span>
            </div>
            <div className="health-row">
              <span className="flex items-center">
                <span className="health-dot"></span>Redis / Queue
              </span>
              <span className="badge on">Online</span>
            </div>
            <div className="health-row">
              <span className="flex items-center">
                <span className="health-dot" style={{ background: 'var(--amber)' }}></span>WebSocket
              </span>
              <span className="badge pending">کند</span>
            </div>
            <div className="health-row">
              <span className="flex items-center">
                <span className="health-dot"></span>Storage
              </span>
              <span className="badge on">Online</span>
            </div>
          </div>
          <div className="helper mt-2.5 text-[11px] text-ink-soft border-t border-line-soft pt-2">
            کاربران فعال: ۲۴ · آزمون فعال: ۱
          </div>
        </div>
      </div>
    </div>
  );
}

export { DashboardPage };
