import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, PageHeader, Spinner } from '../../components/ui/index.js';
import QRCodeImage from '../../components/ui/QRCodeImage.jsx';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function CandidateCardsPage() {
  const [cards, setCards] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [regeneratingId, setRegeneratingId] = useState(null);

  const loadCards = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/candidates/entry-cards`);
      const data = res.data?.data || [];
      setCards(data);
    } catch {
      notifyError('خطا در بارگذاری کارت‌های ورود متقاضیان.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCards();
  }, [loadCards]);

  // ابطال و صدور مجدد کارت ورود و توکن QR
  const handleRegenerate = async (candidateId) => {
    setRegeneratingId(candidateId);
    try {
      const res = await api.post(`${API_BASE}/admin/candidates/${candidateId}/regenerate-card`);
      notifySuccess(res.data?.message || 'کارت ورود با توکن امن جدید صادر شد.');
      await loadCards();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در صدور مجدد کارت ورود.';
      notifyError(msg);
    } finally {
      setRegeneratingId(null);
    }
  };

  const handlePrintAll = () => {
    window.print();
  };

  const handlePrintSingle = (cardId) => {
    // پرینت کارت خاص با اسکرول یا حالت پرینت
    window.print();
  };

  const filteredCards = cards.filter((c) => {
    if (!search.trim()) return true;
    const s = search.trim().toLowerCase();
    return (
      c.name?.toLowerCase().includes(s) ||
      c.code?.toLowerCase().includes(s) ||
      c.username?.toLowerCase().includes(s) ||
      c.exam?.toLowerCase().includes(s)
    );
  });

  return (
    <div className="space-y-4 font-vazir text-xs">
      {/* هدر صفحه — در زمان پرینت مخفی می‌شود */}
      <div className="print:hidden">
        <PageHeader
          title="کارت‌های رسمی ورود به جلسه آزمون"
          crumb={['پوهنتون کندز', 'متقاضیان', 'کارت‌های ورود']}
          actions={
            <div className="flex items-center gap-2 flex-wrap">
              <Button
                variant="outline"
                size="sm"
                onClick={loadCards}
                disabled={loading}
              >
                {loading ? <Spinner size="sm" /> : '⟳ بازخوانی'}
              </Button>
              <Link to="/admin/candidates/checkin">
                <Button variant="outline" size="sm">
                  📋 ثبت حضور و Check-in
                </Button>
              </Link>
              <Button
                variant="gold"
                size="sm"
                onClick={handlePrintAll}
                disabled={filteredCards.length === 0}
                className="font-bold"
              >
                🖨️ چاپ همه کارت‌ها ({filteredCards.length})
              </Button>
            </div>
          }
        />
      </div>

      {/* نوار جستجو و فیلتر — در زمان پرینت مخفی می‌شود */}
      <div className="card p-3 print:hidden flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2 flex-1 min-w-[240px]">
          <span className="text-ink-soft">جستجو:</span>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="جستجو در نام، کد متقاضی یا عنوان آزمون..."
            className="flex-1 rounded-xl border border-line px-3 py-1.5 text-xs outline-none focus:ring-1 focus:ring-navy"
          />
        </div>
        <div className="text-ink-soft text-[11px]">
          تعداد کل کارت‌های آماده چاپ: <b className="text-navy">{filteredCards.length}</b> کارت
        </div>
      </div>

      {loading && (
        <div className="card py-16 text-center text-ink-soft text-xs flex flex-col items-center justify-center gap-3">
          <Spinner size="md" />
          <span>در حال استعلام و تولید کارت‌های ورود متقاضیان از سرور...</span>
        </div>
      )}

      {!loading && filteredCards.length === 0 && (
        <div className="card py-16 text-center text-ink-soft text-xs space-y-2">
          <div className="text-2xl">🪪</div>
          <div className="font-bold text-navy">هیچ کارت ورودی یافت نشد.</div>
          <div>برای متقاضیانی که به آزمون تخصیص یافته‌اند، کارت ورود به صورت خودکار صادر می‌گردد.</div>
          <div className="pt-2">
            <Link to="/admin/candidates">
              <Button variant="gold" size="sm">
                ← بازگشت به مدیریت متقاضیان و تخصیص آزمون
              </Button>
            </Link>
          </div>
        </div>
      )}

      {/* گرید کارت‌های ورود رسمی با QR Code واقعی */}
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 print:grid-cols-2 print:gap-4">
        {filteredCards.map((c) => {
          const isBusy = regeneratingId === (c.candidate_id || c.id);
          return (
            <div
              key={c.id}
              className="card border-2 border-navy rounded-2xl p-4 bg-white shadow-sm flex flex-col justify-between space-y-3 print:border-navy print:shadow-none print:break-inside-avoid"
            >
              {/* سربرگ کارت */}
              <div className="border-b border-navy/20 pb-2 flex items-center justify-between">
                <div>
                  <div className="text-[9px] text-ink-soft font-bold">پوهنتون کندز — معاونیت امور علمی</div>
                  <div className="font-black text-navy text-[13px] mt-0.5 truncate max-w-[200px]" title={c.exam}>
                    {c.exam || 'آزمون عمومی پوهنتون کندز'}
                  </div>
                </div>
                <div className="text-end">
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-navy/10 text-navy">
                    کارت ورود
                  </span>
                </div>
              </div>

              {/* بدنه کارت: مشخصات و QR Code واقعی */}
              <div className="flex items-center gap-3.5">
                {/* تولید تصویر QR Code واقعی با کتابخانه استانداردی */}
                <div className="shrink-0 flex flex-col items-center">
                  <QRCodeImage
                    value={c.qr_token || `AZMOON:${c.code}`}
                    size={96}
                    alt={`QR Code ${c.code}`}
                  />
                  <span className="text-[8.5px] font-mono text-ink-soft mt-1 truncate max-w-[96px]">
                    {c.qr_token?.slice(0, 8)}...
                  </span>
                </div>

                {/* مشخصات هویتی و تخصیص */}
                <div className="flex-1 min-w-0 space-y-1 text-[11px] leading-relaxed">
                  <div>
                    نام داوطلب: <b className="text-navy font-bold text-xs">{c.name}</b>
                  </div>
                  <div>
                    کد داوطلبی: <b className="font-mono text-navy font-bold">{c.code}</b>
                  </div>
                  <div>
                    نام کاربری: <b className="font-mono text-ink-soft">{c.username || c.code?.toLowerCase()}</b>
                  </div>
                  <div className="pt-1 border-t border-line/60 text-ink-soft">
                    شیفت: <b className="text-navy">{c.time}</b>
                  </div>
                  <div className="text-ink-soft">
                    محل: <b className="text-navy">{c.lab}</b> · سیستم: <b className="text-teal font-mono font-bold">{c.computer}</b>
                  </div>
                </div>
              </div>

              {/* فوتر کارت — دکمه‌های عملیات (در پرینت پنهان می‌شوند) */}
              <div className="pt-2 border-t border-line/60 flex items-center justify-between print:hidden text-[11px]">
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-amber hover:bg-amber/10 text-[11px]"
                  loading={isBusy}
                  onClick={() => handleRegenerate(c.candidate_id || c.id)}
                  title="ابطال توکن قبلی و صدور QR جدید"
                >
                  🔄 صدور مجدد
                </Button>

                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => handlePrintSingle(c.id)}
                  title="چاپ این کارت"
                >
                  🖨️ چاپ
                </Button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export { CandidateCardsPage };
