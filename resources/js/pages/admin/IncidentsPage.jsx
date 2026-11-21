import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

export default function IncidentsPage() {
  const [incidents, setIncidents] = useState([
    {
      id: '#102',
      candidate: 'سمیع الله نظری (KND003)',
      computer: 'LAB2-PC16',
      problem: 'خاموشی کامپیوتر',
      action: 'انتقال Session',
      reporter: 'لیلا رحیمی',
      time: '۱۰:۱۸',
    },
    {
      id: '#101',
      candidate: '—',
      computer: 'لابراتوار ۱',
      problem: 'قطعی برق ۲ دقیقه‌ای',
      action: 'ادامهٔ خودکار پس از بازگشت برق',
      reporter: 'لیلا رحیمی',
      time: '۰۹:۳۰',
    },
  ]);

  const [modalOpen, setModalOpen] = useState(false);
  const [newIncident, setNewIncident] = useState({
    candidate: '',
    computer: 'LAB1-PC05',
    problem: 'مشکل شبکه',
    action: 'بررسی کابل شبکه',
  });

  const handleAddIncident = (e) => {
    e.preventDefault();
    const created = {
      id: `#${103 + incidents.length}`,
      candidate: newIncident.candidate || '—',
      computer: newIncident.computer,
      problem: newIncident.problem,
      action: newIncident.action,
      reporter: 'ایمل صافی',
      time: '۱۰:۴۵',
    };
    setIncidents([created, ...incidents]);
    setModalOpen(false);
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="مدیریت حوادث امتحان"
        crumb={['جلسات و لابراتوار', 'حوادث']}
        actions={
          <button
            type="button"
            onClick={() => setModalOpen(true)}
            className="btn btn-outline text-xs font-semibold cursor-pointer"
          >
            + ثبت حادثهٔ جدید
          </button>
        }
      />

      <div className="card overflow-x-auto">
        <table className="w-full text-right text-xs">
          <thead>
            <tr className="border-b border-line text-ink-soft">
              <th className="py-2.5 px-3">#</th>
              <th className="py-2.5 px-3">متقاضی</th>
              <th className="py-2.5 px-3">کامپیوتر</th>
              <th className="py-2.5 px-3">مشکل</th>
              <th className="py-2.5 px-3">اقدام</th>
              <th className="py-2.5 px-3">مسئول</th>
              <th className="py-2.5 px-3">زمان</th>
            </tr>
          </thead>
          <tbody>
            {incidents.map((row) => (
              <tr key={row.id} className="border-b border-line-soft hover:bg-row-hover">
                <td className="py-2.5 px-3 font-mono font-bold text-navy">{row.id}</td>
                <td className="py-2.5 px-3 font-medium text-ink">{row.candidate}</td>
                <td className="py-2.5 px-3 font-mono text-ink-soft">{row.computer}</td>
                <td className="py-2.5 px-3 text-red font-semibold">{row.problem}</td>
                <td className="py-2.5 px-3 text-teal font-medium">{row.action}</td>
                <td className="py-2.5 px-3 text-ink-soft">{row.reporter}</td>
                <td className="py-2.5 px-3 font-mono text-ink-soft">{row.time}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Modal ثبت حادثه جدید */}
      {modalOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="card w-full max-w-[440px] bg-white p-5 space-y-4">
            <div className="font-bold text-navy text-[15px]">ثبت حادثهٔ جدید امتحان</div>
            <form onSubmit={handleAddIncident} className="space-y-3 text-xs">
              <div className="field space-y-1">
                <label className="font-semibold text-ink">متقاضی (اختیاری)</label>
                <input
                  type="text"
                  placeholder="مثلاً KND003 یا نام داوطلب"
                  value={newIncident.candidate}
                  onChange={(e) => setNewIncident({ ...newIncident, candidate: e.target.value })}
                  className="w-full rounded border border-line p-2 text-xs"
                />
              </div>
              <div className="field space-y-1">
                <label className="font-semibold text-ink">کامپیوتر / لابراتوار</label>
                <input
                  type="text"
                  value={newIncident.computer}
                  onChange={(e) => setNewIncident({ ...newIncident, computer: e.target.value })}
                  className="w-full rounded border border-line p-2 text-xs"
                  required
                />
              </div>
              <div className="field space-y-1">
                <label className="font-semibold text-ink">شرح مشکل</label>
                <input
                  type="text"
                  value={newIncident.problem}
                  onChange={(e) => setNewIncident({ ...newIncident, problem: e.target.value })}
                  className="w-full rounded border border-line p-2 text-xs"
                  required
                />
              </div>
              <div className="field space-y-1">
                <label className="font-semibold text-ink">اقدام انجام‌شده</label>
                <input
                  type="text"
                  value={newIncident.action}
                  onChange={(e) => setNewIncident({ ...newIncident, action: e.target.value })}
                  className="w-full rounded border border-line p-2 text-xs"
                  required
                />
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setModalOpen(false)}
                  className="btn btn-outline text-xs"
                >
                  انصراف
                </button>
                <button type="submit" className="btn btn-primary text-xs">
                  ثبت حادثه
                </button>
              </div>
            </form>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export { IncidentsPage };
