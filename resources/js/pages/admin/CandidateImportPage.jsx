import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

export default function CandidateImportPage() {
  const [imported, setImported] = useState(false);

  return (
    <div className="max-w-[680px] space-y-4">
      <PageHeader
        title="وارد کردن متقاضیان از Excel"
        crumb={['متقاضیان', 'Import از اکسل']}
      />

      <div className="card space-y-4">
        {/* Dropzone */}
        <div className="border-[1.5px] border-dashed border-line rounded-[9px] p-6 text-center text-ink-soft text-xs space-y-1 bg-paper/30">
          <div>فایل اکسل (مثلاً candidates-batch-c.xlsx) را اینجا رها کنید</div>
          <div>یا <span className="text-navy font-semibold cursor-pointer underline">انتخاب فایل از سیستم</span></div>
        </div>

        {/* تطبیق ستون‌ها */}
        <div>
          <div className="section-title text-[14px] font-bold text-navy mb-2">تطبیق ستون‌ها</div>
          <table className="w-full text-right text-xs">
            <thead>
              <tr className="border-b border-line text-ink-soft">
                <th className="py-2 px-3">ستون فایل</th>
                <th className="py-2 px-3">فیلد سیستم</th>
              </tr>
            </thead>
            <tbody>
              <tr className="border-b border-line-soft">
                <td className="py-2.5 px-3 font-mono font-medium">Full Name</td>
                <td className="py-2.5 px-3">
                  <select className="field rounded-md border border-line p-1 text-xs text-ink bg-white">
                    <option>نام کامل</option>
                  </select>
                </td>
              </tr>
              <tr className="border-b border-line-soft">
                <td className="py-2.5 px-3 font-mono font-medium">National ID</td>
                <td className="py-2.5 px-3">
                  <select className="field rounded-md border border-line p-1 text-xs text-ink bg-white">
                    <option>کد ملی / تذکره</option>
                  </select>
                </td>
              </tr>
              <tr className="border-b border-line-soft">
                <td className="py-2.5 px-3 font-mono font-medium">Exam Group</td>
                <td className="py-2.5 px-3">
                  <select className="field rounded-md border border-line p-1 text-xs text-ink bg-white">
                    <option>آزمون تخصیص‌یافته</option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        {/* نتیجه اعتبارسنجی */}
        <div>
          <div className="section-title text-[14px] font-bold text-navy mb-2.5">نتیجهٔ اعتبارسنجی (Validation)</div>
          <div className="grid grid-cols-3 gap-3">
            <div className="stat" style={{ '--stat-c': 'var(--teal)' }}>
              <div className="num text-xl font-bold text-teal">۲۱</div>
              <div className="lbl text-xs text-ink-soft">معتبر</div>
            </div>
            <div className="stat" style={{ '--stat-c': 'var(--amber)' }}>
              <div className="num text-xl font-bold text-amber">۲</div>
              <div className="lbl text-xs text-ink-soft">تکراری</div>
            </div>
            <div className="stat" style={{ '--stat-c': 'var(--red)' }}>
              <div className="num text-xl font-bold text-red">۱</div>
              <div className="lbl text-xs text-ink-soft">نامعتبر</div>
            </div>
          </div>
        </div>

        <button
          type="button"
          onClick={() => setImported(true)}
          disabled={imported}
          className="btn btn-primary w-full justify-center py-2.5 text-xs font-semibold cursor-pointer disabled:opacity-60"
        >
          {imported ? '✓ ۲۱ متقاضی با موفقیت وارد شدند' : 'تأیید و وارد کردن ۲۱ متقاضی'}
        </button>
      </div>
    </div>
  );
}

export { CandidateImportPage };
