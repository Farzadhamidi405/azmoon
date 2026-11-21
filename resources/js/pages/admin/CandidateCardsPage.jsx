import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

function PseudoQR({ seed = 'card' }) {
  let num = 0;
  for (const ch of seed) num += ch.charCodeAt(0);
  const cells = [];
  for (let i = 0; i < 64; i++) {
    num = (num * 9301 + 49297) % 233280;
    const on = num / 233280 > 0.42;
    const r = Math.floor(i / 8);
    const c = i % 8;
    const inCorner = (r < 3 && c < 3) || (r < 3 && c > 4) || (r > 4 && c < 3);
    cells.push(inCorner || on);
  }

  return (
    <div className="qr-box size-[88px] shrink-0 bg-white border border-line rounded p-1.5 grid grid-cols-8 grid-rows-8 gap-[1px]">
      {cells.map((active, idx) => (
        <i key={idx} className={active ? 'bg-ink rounded-[1px]' : 'bg-transparent'} />
      ))}
    </div>
  );
}

export default function CandidateCardsPage() {
  const [cards] = useState([
    { id: 'qr1', name: 'احمد ولی', code: 'KND001', lab: 'Lab2/PC14', time: '۱۴۰۴/۰۶/۱۴ — ۱۰:۰۰', exam: 'English Language Exam' },
    { id: 'qr2', name: 'مریم رضایی', code: 'KND002', lab: 'Lab2/PC15', time: '۱۴۰۴/۰۶/۱۴ — ۱۰:۰۰', exam: 'English Language Exam' },
    { id: 'qr3', name: 'سمیع الله نظری', code: 'KND003', lab: 'Lab2/PC16', time: '۱۴۰۴/۰۶/۱۴ — ۱۰:۰۰', exam: 'English Language Exam' },
    { id: 'qr4', name: 'حامد کریمی', code: 'KND004', lab: 'Lab1/PC02', time: '۱۴۰۴/۰۶/۱۴ — ۱۱:۳۰', exam: 'English Language Exam' },
    { id: 'qr5', name: 'زهرا حسینی', code: 'KND005', lab: 'Lab1/PC03', time: '۱۴۰۴/۰۶/۱۴ — ۱۱:۳۰', exam: 'English Language Exam' },
    { id: 'qr6', name: 'امید سعادت', code: 'KND006', lab: 'Lab1/PC04', time: '۱۴۰۴/۰۶/۱۴ — ۱۱:۳۰', exam: 'English Language Exam' },
  ]);

  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="کارت‌های ورود امتحان"
        crumb={['متقاضیان', 'کارت‌های ورود']}
        actions={
          <button type="button" onClick={handlePrint} className="btn btn-gold text-xs font-semibold cursor-pointer">
            چاپ همه ({cards.length})
          </button>
        }
      />

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {cards.map((c) => (
          <div
            key={c.id}
            className="card border-[1.5px] border-navy flex gap-3 p-4 bg-white rounded-lg shadow-sm"
          >
            <PseudoQR seed={c.code} />
            <div className="flex-1 min-w-0">
              <div className="text-[10px] text-ink-soft">پوهنتون کندز</div>
              <div className="font-extrabold text-[12.5px] text-navy mt-0.5 mb-2 truncate">{c.exam}</div>
              <div className="text-[11.5px] leading-relaxed text-ink">
                <div className="font-bold">{c.name}</div>
                <div className="font-mono text-ink-soft">{c.code} — {c.lab}</div>
                <div className="text-ink-soft text-[11px]">{c.time}</div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

export { CandidateCardsPage };
