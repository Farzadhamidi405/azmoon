import { useCallback, useEffect, useState } from 'react';
import { PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError } from '../../lib/notify.js';

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
  const [cards, setCards] = useState([]);
  const [loading, setLoading] = useState(true);

  const loadCards = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/candidates/entry-cards`);
      const data = res.data?.data || [];
      setCards(data);
    } catch (err) {
      notifyError('خطا در بارگذاری کارت‌های ورود متقاضیان.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCards();
  }, [loadCards]);

  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="space-y-4">
      <PageHeader
        title="کارت‌های ورود امتحان"
        crumb={['متقاضیان', 'کارت‌های ورود']}
        actions={
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={loadCards}
              disabled={loading}
              className="btn btn-outline text-xs cursor-pointer"
            >
              {loading ? <Spinner size="sm" /> : '⟳ بازخوانی'}
            </button>
            <button
              type="button"
              onClick={handlePrint}
              disabled={cards.length === 0}
              className="btn btn-gold text-xs font-semibold cursor-pointer disabled:opacity-50"
            >
              چاپ همه ({cards.length})
            </button>
          </div>
        }
      />

      {loading && (
        <div className="card py-12 text-center text-ink-soft text-xs flex flex-col items-center gap-2">
          <Spinner size="md" />
          <span>در حال دریافت اطلاعات کارت‌های ورود از سرور...</span>
        </div>
      )}

      {!loading && cards.length === 0 && (
        <div className="card py-12 text-center text-ink-soft text-xs">
          هیچ متقاضی یا کارتی در سیستم یافت نشد. لطفاً ابتدا از بخش متقاضیان، داوطلبان را ثبت کنید.
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {cards.map((c) => (
          <div
            key={c.id}
            className="card border-[1.5px] border-navy flex gap-3 p-4 bg-white rounded-lg shadow-sm"
          >
            <PseudoQR seed={c.qr_token || c.code} />
            <div className="flex-1 min-w-0">
              <div className="text-[10px] text-ink-soft">پوهنتون کندز</div>
              <div className="font-extrabold text-[12.5px] text-navy mt-0.5 mb-2 truncate">{c.exam}</div>
              <div className="text-[11.5px] leading-relaxed text-ink">
                <div className="font-bold">{c.name}</div>
                <div className="font-mono text-ink-soft">
                  {c.code} — {c.lab}
                </div>
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
