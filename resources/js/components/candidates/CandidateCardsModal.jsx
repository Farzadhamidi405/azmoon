import { Modal, Button } from '../ui/index.js';

export default function CandidateCardsModal({ open, onClose, candidates = [] }) {
  const handlePrint = () => {
    window.print();
  };

  return (
    <Modal open={open} onClose={onClose} title="کارت‌های ورود به جلسه آزمون" size="xl">
      <div className="space-y-4">
        <div className="flex justify-between items-center pb-2 border-b border-line">
          <div className="text-xs text-ink-soft">
            تعداد کارت‌ها: <b className="text-navy">{candidates.length}</b> کارت
          </div>
          <Button variant="gold" size="sm" onClick={handlePrint}>
            🖨️ چاپ کارت‌ها
          </Button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[500px] overflow-y-auto p-1">
          {candidates.map((cand) => (
            <div
              key={cand.id}
              className="border-2 border-navy rounded-xl p-3.5 bg-panel shadow-sm flex gap-3 text-xs"
            >
              {/* Pseudo QR code */}
              <div className="size-20 bg-paper border border-line rounded p-1.5 grid grid-cols-6 grid-rows-6 gap-0.5 shrink-0">
                {Array.from({ length: 36 }).map((_, idx) => {
                  const isCorner =
                    (idx < 3 || idx === 6 || idx === 7 || idx === 12 || idx === 13) ||
                    (idx >= 30 || idx === 24 || idx === 25);
                  const active = isCorner || (idx * (cand.id || 7) + 13) % 3 === 0;
                  return (
                    <div
                      key={idx}
                      className={active ? 'bg-navy rounded-[1px]' : 'bg-transparent'}
                    />
                  );
                })}
              </div>

              <div className="flex-1 space-y-1">
                <div className="text-[10px] text-ink-soft">پوهنتون کندز — معاونیت علمی</div>
                <div className="font-extrabold text-navy text-sm">کارت ورود به جلسه امتحان</div>
                <div className="text-ink">
                  نام: <b>{cand.name || 'متقاضی آزمون'}</b>
                </div>
                <div className="font-mono text-ink-soft">
                  کد متقاضی: <b className="text-navy">{cand.candidate_code}</b>
                </div>
                <div className="text-[11px] text-ink-soft">
                  {cand.assignment?.lab?.name ? `${cand.assignment.lab.name} · ` : ''}
                  {cand.assignment?.computer?.computer_code ? `سیستم: ${cand.assignment.computer.computer_code}` : 'صندلی: بر اساس شیفت'}
                </div>
              </div>
            </div>
          ))}
        </div>

        <div className="flex justify-end pt-2 border-t border-line">
          <Button variant="outline" onClick={onClose}>
            بستن
          </Button>
        </div>
      </div>
    </Modal>
  );
}
