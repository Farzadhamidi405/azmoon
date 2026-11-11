import { useEffect, useState } from 'react';
import { Badge, Button, Modal, Spinner } from '../ui/index.js';
import formatDate from '../../lib/formatDate.js';

export default function CandidateResultModal({ open, onClose, attemptId, onGetResult }) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (open && attemptId) {
      setLoading(true);
      onGetResult(attemptId)
        .then((res) => setData(res))
        .catch(() => setData(null))
        .finally(() => setLoading(false));
    }
  }, [open, attemptId]);

  const candidate = data?.candidate || {};
  const result = data?.result || {};
  const sections = data?.sections || [];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`کارنامه تفصیلی متقاضی: ${candidate?.name || ''}`}
      size="lg"
    >
      {loading ? (
        <div className="py-12 flex justify-center items-center gap-2 text-ink-soft">
          <Spinner size="sm" />
          <span>در حال دریافت کارنامه تفصیلی...</span>
        </div>
      ) : !data ? (
        <div className="text-center py-6 text-sm text-ink-soft">
          اطلاعات کارنامه یافت نشد یا هنوز تصحیح نشده است.
        </div>
      ) : (
        <div className="space-y-4">
          {/* Top Score Banner */}
          <div className="p-4 rounded-xl bg-paper/70 border border-line flex flex-col md:flex-row justify-between items-center gap-3">
            <div>
              <div className="font-black text-navy text-base">{candidate?.name}</div>
              <div className="text-xs text-ink-soft">
                کد متقاضی: {candidate?.candidate_code} · تاریخ آزمون: {formatDate(data?.submitted_at || data?.created_at)}
              </div>
            </div>
            <div className="flex items-center gap-4">
              <div className="text-center">
                <div className="text-xs text-ink-soft">نمره کل</div>
                <div className="text-2xl font-black text-navy">
                  {result?.total_score ?? '—'} <span className="text-xs text-ink-soft font-normal">/ {result?.max_score ?? 100}</span>
                </div>
              </div>
              <div className="text-center">
                <div className="text-xs text-ink-soft">درصد کل</div>
                <div className="text-2xl font-black text-teal">
                  {result?.percentage !== undefined ? `${Math.round(result.percentage)}%` : '—'}
                </div>
              </div>
              <div>
                <Badge tone={result?.is_passed ? 'on' : 'bad'}>
                  {result?.is_passed ? '✓ قبولی' : '✕ مردودی'}
                </Badge>
              </div>
            </div>
          </div>

          {/* Section Breakdown */}
          {sections.length > 0 && (
            <div>
              <div className="text-xs font-bold text-navy mb-2">عملکرد بر تفکیک بخش‌ها (Sections)</div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                {sections.map((sec, i) => (
                  <div key={i} className="p-3 border border-line rounded-lg bg-panel">
                    <div className="text-xs font-bold text-ink">{sec.title || `بخش ${i + 1}`}</div>
                    <div className="flex items-baseline justify-between mt-1">
                      <span className="text-xs text-ink-soft">نمره کسب‌شده:</span>
                      <span className="font-bold text-sm text-navy">{sec.score ?? 0} / {sec.max_score ?? 0}</span>
                    </div>
                    <div className="w-full bg-paper h-1.5 rounded-full mt-2 overflow-hidden">
                      <div
                        className="bg-gold h-full rounded-full"
                        style={{
                          width: `${Math.min(100, Math.round(((sec.score || 0) / (sec.max_score || 1)) * 100))}%`,
                        }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="flex justify-between items-center pt-2 border-t border-line">
            <Button variant="gold" size="sm" onClick={() => window.print()}>
              🖨️ چاپ کارنامه
            </Button>
            <Button variant="outline" onClick={onClose}>
              بستن
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
