import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

export default function ExamPoolPage() {
  const [pools, setPools] = useState([
    { section: 'Grammar', total: 100, selected: 20, randomize: true },
    { section: 'Vocabulary', total: 50, selected: 10, randomize: true },
    { section: 'Reading', total: 30, selected: 10, randomize: true },
    { section: 'Listening', total: 25, selected: 10, randomize: false },
  ]);

  const toggleRandomize = (index) => {
    setPools((prev) =>
      prev.map((p, idx) => (idx === index ? { ...p, randomize: !p.randomize } : p))
    );
  };

  return (
    <div className="max-w-[720px] space-y-4">
      <PageHeader
        title="Question Pool — Batch A"
        crumb={['آزمون‌ها', 'Question Pool']}
      />

      <div className="card">
        <div className="helper mb-4 text-[12.5px] text-ink-soft leading-relaxed">
          به‌جای سوالات ثابت، از هر بخش یک مجموعهٔ تصادفی از Pool انتخاب می‌شود؛ هر متقاضی سوالات و ترتیب گزینه‌های متفاوتی می‌بیند.
        </div>

        <table className="w-full text-right mb-6">
          <thead>
            <tr className="border-b border-line text-ink-soft text-xs">
              <th className="py-2.5 px-3">بخش</th>
              <th className="py-2.5 px-3">مجموع Pool</th>
              <th className="py-2.5 px-3">تعداد انتخابی</th>
              <th className="py-2.5 px-3">Randomize گزینه‌ها</th>
            </tr>
          </thead>
          <tbody>
            {pools.map((item, idx) => (
              <tr key={idx} className="border-b border-line-soft hover:bg-row-hover text-xs">
                <td className="py-3 px-3 font-semibold text-navy">{item.section}</td>
                <td className="py-3 px-3 font-mono">{item.total}</td>
                <td className="py-3 px-3 font-mono font-bold text-teal">{item.selected}</td>
                <td className="py-3 px-3">
                  <button
                    type="button"
                    onClick={() => toggleRandomize(idx)}
                    className={`chk cursor-pointer ${item.randomize ? 'on' : ''}`}
                    title="تغییر وضعیت تصادفی"
                  >
                    {item.randomize ? '✓' : ''}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="section-title text-[14px] font-bold text-navy mb-3">پیش‌نمایش تخصیص</div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="stat">
            <div className="lbl text-xs text-ink-soft">احمد ولی</div>
            <div className="num text-[14px] font-mono mt-1 text-navy font-bold">Q1, Q5, Q17, Q22 …</div>
          </div>
          <div className="stat">
            <div className="lbl text-xs text-ink-soft">مریم رضایی</div>
            <div className="num text-[14px] font-mono mt-1 text-navy font-bold">Q3, Q8, Q19, Q27 …</div>
          </div>
        </div>
      </div>
    </div>
  );
}

export { ExamPoolPage };
