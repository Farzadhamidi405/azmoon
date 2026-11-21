import { useState } from 'react';
import { Button, PageHeader, StatCard, StatGrid } from '../../components/ui/index.js';
import useDashboardData from '../../hooks/useDashboardData.js';
import { useAuth } from '../../hooks/useAuth.js';

const TEXT = Object.freeze({
  title: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F \u0645\u062F\u06CC\u0631\u06CC\u062A',
  crumbRoot: '\u067E\u0648\u0647\u0646\u062A\u0648\u0646 \u06A9\u0646\u062F\u0632',
  crumbPage: '\u062F\u0627\u0634\u0628\u0648\u0631\u062F',
  refresh: '\u062A\u0627\u0632\u0647\u200C\u0633\u0627\u0632\u06CC',
});

export default function DashboardPage() {
  const { user } = useAuth();
  const { data, reload, busy } = useDashboardData(user);
  const { published, candidates, inProgress, activity } = data;

  const [activeSessions] = useState([
    { name: '\u0627\u062D\u0645\u062F \u0648\u0644\u06CC', exam: 'Batch A', q: '\u06F2\u06F1 / \u06F5\u06F0', rem: '\u06F3\u06F8:\u06F1\u06F2', status: 'active', label: '\u062F\u0631 \u062D\u0627\u0644 \u0627\u0645\u062A\u062D\u0627\u0646' },
    { name: '\u0645\u0631\u06CC\u0645 \u0631\u0636\u0627\u06CC\u06CC', exam: 'Batch A', q: '\u06F7 / \u06F5\u06F0', rem: '\u06F5\u06F2:\u06F4\u06F0', status: 'active', label: '\u062F\u0631 \u062D\u0627\u0644 \u0627\u0645\u062A\u062D\u0627\u0646' },
    { name: '\u0633\u0645\u06CC\u0639 \u0627\u0644\u0644\u0647', exam: 'Batch B', q: '\u2014', rem: '\u2014', status: 'disconnected', label: '\u0642\u0637\u0639 \u0627\u062A\u0635\u0627\u0644' },
  ]);

  const examsCount = published?.status === 'ok' ? published.value : '\u06F3';
  const candidatesCount = candidates?.status === 'ok' ? candidates.value : '\u06F1\u06F2\u06F4';
  const inProgressCount = inProgress?.status === 'ok' ? inProgress.value : '\u06F1\u06F8';

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

      {/* ۴ کارت آمار مطابق نمونه مرجع Prototype */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3.5">
        <div className="stat" style={{ '--stat-c': 'var(--gold)' }}>
          <div className="num">{examsCount}</div>
          <div className="lbl">آزمون فعال</div>
        </div>
        <div className="stat" style={{ '--stat-c': 'var(--navy-3)' }}>
          <div className="num">{candidatesCount}</div>
          <div className="lbl">متقاضی ثبت‌شده</div>
        </div>
        <div className="stat" style={{ '--stat-c': 'var(--teal)' }}>
          <div className="num">{inProgressCount}</div>
          <div className="lbl">در حال امتحان دادن</div>
        </div>
        <div className="stat" style={{ '--stat-c': 'var(--amber)' }}>
          <div className="num">۹۶٪</div>
          <div className="lbl">نرخ تکمیل امتحان</div>
        </div>
      </div>

      {/* بخش پایینی: جلسات فعال + فعالیت‌های اخیر + سلامت سیستم */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
        {/* جدول جلسات فعال اکنون */}
        <div className="card lg:col-span-6 overflow-x-auto">
          <div className="font-bold text-navy text-[14px] mb-3 flex items-center justify-between">
            <span>جلسات فعال اکنون</span>
            <span className="badge on text-[10.5px]">۳ جلسه در جریان</span>
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
                  <td className="py-2.5 px-3 font-semibold text-ink">{sess.name}</td>
                  <td className="py-2.5 px-3 text-ink-soft">{sess.exam}</td>
                  <td className="py-2.5 px-3">{sess.q}</td>
                  <td className="py-2.5 px-3 font-mono font-bold text-navy">{sess.rem}</td>
                  <td className="py-2.5 px-3">
                    <span className={sess.status === 'active' ? 'badge on' : 'badge bad'}>
                      {sess.label}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* کارت فعالیت‌های اخیر */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3">فعالیت‌های اخیر</div>
          <div className="text-[12.5px] leading-[2.2] text-ink-soft">
            <div>• افزوده‌شدن ۲۰ متقاضی جدید</div>
            <div>• آزمون «Batch B» منتشر شد</div>
            <div>• ۶ سوال گرامر اضافه شد</div>
            <div>• کارت‌های ورود چاپ شد</div>
          </div>
        </div>

        {/* کارت System Health */}
        <div className="card lg:col-span-3">
          <div className="font-bold text-navy text-[14px] mb-3">System Health</div>
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
