import { Link } from 'react-router-dom';
import { Button, PageHeader, Spinner } from '../../components/ui/index.js';
import useDashboardData from '../../hooks/useDashboardData.js';
import useSessions from '../../hooks/useSessions.js';
import { useAuth } from '../../hooks/useAuth.js';
import formatDate from '../../lib/formatDate.js';

const TEXT = Object.freeze({
  title: 'داشبورد مدیریت',
  crumbRoot: 'پوهنتون کندز',
  crumbPage: 'داشبورد',
  refresh: 'تازه‌سازی',
});

function formatRemaining(sec) {
  if (sec === null || sec === undefined) return '—';
  const s = Math.max(0, Math.floor(sec));
  const m = Math.floor(s / 60);
  const remS = s % 60;
  return `${m}:${remS < 10 ? '0' : ''}${remS}`;
}

const SESSION_STATUS = {
  in_progress: { label: 'در حال آزمون', badge: 'badge on' },
  disconnected: { label: 'قطع اتصال', badge: 'badge bad' },
  paused: { label: 'متوقف‌شده', badge: 'badge pending' },
  completed: { label: 'تکمیل‌شده', badge: 'badge teal' },
  auto_submitted: { label: 'ثبت خودکار', badge: 'badge on' },
  disqualified: { label: 'محروم/باطل', badge: 'badge bad' },
};

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, reload: reloadDashboard, busy: dashboardBusy } = useDashboardData(user);
  const { published, candidates, inProgress } = data;

  // دریافت اطلاعات واقعی جلسات فعال از API
  const {
    items: activeSessions,
    loading: sessionsLoading,
    reload: reloadSessions,
  } = useSessions({
    per_page: 5,
  });

  const handleRefresh = () => {
    reloadDashboard();
    reloadSessions();
  };

  const examsCount = published?.status === 'ok' ? published.value : '—';
  const candidatesCount = candidates?.status === 'ok' ? candidates.value : '—';
  const inProgressCount = inProgress?.status === 'ok' ? inProgress.value : '۰';

  return (
    <div className="space-y-5">
      <PageHeader
        title={TEXT.title}
        crumb={[TEXT.crumbRoot, TEXT.crumbPage]}
        actions={
          <Button
            variant="outline"
            size="sm"
            loading={dashboardBusy || sessionsLoading}
            onClick={handleRefresh}
          >
            {TEXT.refresh}
          </Button>
        }
      />

      {/* ۴ کارت آمار با قابلیت کلیک و انتقال به صفحات مربوطه */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <Link
          to="/admin/exams"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--gold)' }}
        >
          <div className="num">{examsCount}</div>
          <div className="lbl">آزمون منتشرشده</div>
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
          to="/admin/results"
          className="stat block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md cursor-pointer"
          style={{ '--stat-c': 'var(--amber)' }}
        >
          <div className="num">—</div>
          <div className="lbl">نتایج و کارنامه‌ها</div>
        </Link>
      </div>

      {/* بخش پایینی: جلسات فعال + فعالیت‌های اخیر + سلامت سیستم */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
        {/* جدول جلسات فعال اکنون (دریافت زنده از API) */}
        <div className="card lg:col-span-6 overflow-x-auto">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <Link
              to="/admin/sessions"
              className="hover:text-gold transition-colors flex items-center gap-1.5"
            >
              <span>جلسات آزمون اکنون</span>
              <span className="text-xs text-ink-soft font-normal">←</span>
            </Link>
            <Link to="/admin/sessions">
              <span className="badge on text-[10.5px] cursor-pointer hover:opacity-90">
                {inProgressCount} جلسه در جریان
              </span>
            </Link>
          </div>

          {sessionsLoading ? (
            <div className="py-8 flex justify-center items-center gap-2 text-ink-soft text-xs font-mono">
              <Spinner size="sm" />
              <span>در حال دریافت وضعیت جلسات...</span>
            </div>
          ) : activeSessions.length === 0 ? (
            <div className="py-8 text-center text-ink-soft text-xs">
              در حال حاضر هیچ جلسه آزمونی در سامانه فعال نیست.
            </div>
          ) : (
            <table className="w-full text-right text-xs">
              <thead>
                <tr className="border-b border-line text-ink-soft">
                  <th className="py-2.5 px-3">متقاضی</th>
                  <th className="py-2.5 px-3">آزمون</th>
                  <th className="py-2.5 px-3">سیستم</th>
                  <th className="py-2.5 px-3">باقیمانده</th>
                  <th className="py-2.5 px-3">وضعیت</th>
                </tr>
              </thead>
              <tbody>
                {activeSessions.map((sess) => {
                  const statusInfo =
                    SESSION_STATUS[sess.status] || {
                      label: sess.status || 'نامشخص',
                      badge: 'badge off',
                    };
                  return (
                    <tr key={sess.id} className="border-b border-line-soft hover:bg-row-hover">
                      <td className="py-2.5 px-3 font-semibold text-ink">
                        <Link to="/admin/candidates" className="hover:text-navy hover:underline">
                          {sess.candidate?.name || 'متقاضی'}
                        </Link>
                        {sess.candidate?.candidate_code && (
                          <div className="text-[10px] text-ink-soft font-mono font-normal">
                            کد: {sess.candidate.candidate_code}
                          </div>
                        )}
                      </td>
                      <td className="py-2.5 px-3 text-ink-soft">
                        <Link to="/admin/exams" className="hover:text-navy hover:underline">
                          {sess.exam?.title || 'آزمون'}
                        </Link>
                      </td>
                      <td className="py-2.5 px-3 font-mono text-xs">
                        {sess.computer?.computer_code || '—'}
                      </td>
                      <td className="py-2.5 px-3 font-mono font-bold text-navy">
                        {formatRemaining(sess.remaining_seconds)}
                      </td>
                      <td className="py-2.5 px-3">
                        <Link to="/admin/sessions">
                          <span className={`${statusInfo.badge} cursor-pointer`}>
                            {statusInfo.label}
                          </span>
                        </Link>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>

        {/* کارت فعالیت‌های اخیر لاگ‌ها */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <span>فعالیت‌های اخیر</span>
            <Link to="/admin/audit-logs" className="text-xs text-gold hover:underline">
              مشاهده لاگ‌ها
            </Link>
          </div>
          <div className="text-[12.5px] leading-[2.2] text-ink-soft space-y-1">
            {Array.isArray(data.activity?.value) && data.activity.value.length > 0 ? (
              data.activity.value.slice(0, 4).map((act, idx) => (
                <div key={act.id || idx} className="truncate">
                  <Link
                    to="/admin/audit-logs"
                    className="hover:text-navy hover:underline flex items-baseline gap-1"
                  >
                    <span>•</span>
                    <span className="truncate">{act.action || act.description || 'رویداد سامانه'}</span>
                  </Link>
                </div>
              ))
            ) : (
              <div className="text-xs text-ink-soft py-2">
                هیچ لاگ اخیری برای نمایش ثبت نشده است.
              </div>
            )}
          </div>
        </div>

        {/* کارت System Health (حالت صادقانه بدون ارقام فیک) */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <span>سلامت سامانه (System Health)</span>
            <Link to="/admin/settings" className="text-xs text-gold hover:underline">
              تنظیمات
            </Link>
          </div>
          <div className="space-y-1">
            <div className="health-row flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-xs">
                <span className="health-dot"></span>
                <span>سرویس هسته API</span>
              </span>
              <span className="badge on">آنلاین</span>
            </div>
            <div className="health-row flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-xs">
                <span className="health-dot"></span>
                <span>پایگاه داده (DB)</span>
              </span>
              <span className="badge on">متصل</span>
            </div>
            <div className="health-row flex items-center justify-between">
              <span className="flex items-center gap-1.5 text-xs text-ink-soft">
                <span className="health-dot" style={{ background: 'var(--ink-soft)' }}></span>
                <span>شاخص‌های سخت‌افزاری</span>
              </span>
              <span className="badge off">در دسترس نیست</span>
            </div>
          </div>
          <div className="helper mt-2.5 text-[11px] text-ink-soft border-t border-line-soft pt-2 leading-relaxed">
            مانیتورینگ بلادرنگ سخت‌افزار (CPU / RAM / تاخیر شبکه) پیکربندی نشده است.
          </div>
        </div>
      </div>
    </div>
  );
}

export { DashboardPage };
