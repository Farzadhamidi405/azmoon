import { useState } from 'react';
import { PageHeader } from '../../components/ui/index.js';

export default function CandidateCheckinPage() {
  const [candidates, setCandidates] = useState([
    { code: 'KND001', name: 'احمد ولی', status: 'checked_in' },
    { code: 'KND002', name: 'مریم رضایی', status: 'checked_in' },
    { code: 'KND003', name: 'سمیع الله نظری', status: 'pending' },
    { code: 'KND004', name: 'حامد کریمی', status: 'pending' },
  ]);

  const [currentCandidate, setCurrentCandidate] = useState({
    code: 'KND003',
    name: 'سمیع الله نظری',
    nationalId: '۹۸۷۶۵۴۳۲۱',
  });

  const handleConfirm = () => {
    setCandidates((prev) =>
      prev.map((c) => (c.code === currentCandidate.code ? { ...c, status: 'checked_in' } : c))
    );
    setCurrentCandidate({
      code: 'KND004',
      name: 'حامد کریمی',
      nationalId: '۴۵۶۱۲۳۷۸۹',
    });
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="Check-in و تأیید هویت — Batch A"
        crumb={['متقاضیان', 'Check-in']}
      />

      {/* Stepper */}
      <div className="stepper flex items-center gap-1.5 flex-wrap text-xs">
        <span className="step done">ورود متقاضی</span>
        <span className="step-arrow text-line">←</span>
        <span className="step cur font-bold text-white bg-navy rounded-full px-3 py-1">تأیید هویت</span>
        <span className="step-arrow text-line">←</span>
        <span className="step">تخصیص کامپیوتر</span>
        <span className="step-arrow text-line">←</span>
        <span className="step">شروع امتحان</span>
      </div>

      {/* کارت تایید متقاضی جاری */}
      <div className="card max-w-[480px]">
        <div className="font-bold text-navy text-[14px] mb-2.5">تأیید متقاضی جاری</div>
        <div className="text-[12.5px] leading-relaxed text-ink space-y-1">
          <div>نام: <b>{currentCandidate.name}</b></div>
          <div>کد متقاضی: <b>{currentCandidate.code}</b></div>
          <div>کد ملی/تذکره: <b>{currentCandidate.nationalId}</b></div>
        </div>
        <div className="flex gap-2.5 mt-4">
          <button type="button" onClick={handleConfirm} className="btn btn-teal text-xs">
            ✓ تأیید و Check-in
          </button>
          <button type="button" className="btn btn-outline text-xs">
            عدم تطابق هویت
          </button>
        </div>
      </div>

      {/* جدول وضعیت Check-in متقاضیان */}
      <div className="card">
        <table className="w-full text-right text-xs">
          <thead>
            <tr className="border-b border-line text-ink-soft">
              <th className="py-2.5 px-3">کد متقاضی</th>
              <th className="py-2.5 px-3">نام</th>
              <th className="py-2.5 px-3">وضعیت Check-in</th>
            </tr>
          </thead>
          <tbody>
            {candidates.map((c, idx) => (
              <tr key={idx} className="border-b border-line-soft hover:bg-row-hover">
                <td className="py-2.5 px-3 font-mono font-semibold text-navy">{c.code}</td>
                <td className="py-2.5 px-3 font-medium text-ink">{c.name}</td>
                <td className="py-2.5 px-3">
                  {c.status === 'checked_in' ? (
                    <span className="badge on">✓ Checked-in</span>
                  ) : (
                    <span className="badge pending">در انتظار تأیید</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export { CandidateCheckinPage };
