import { useCallback, useEffect, useState } from 'react';
import { PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function CandidateCheckinPage() {
  const [candidates, setCandidates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [selectedCandidate, setSelectedCandidate] = useState(null);
  const [search, setSearch] = useState('');

  const loadCandidates = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/candidates`, {
        params: { per_page: 50 },
      });
      const list = unwrapList(res.data).items || [];
      setCandidates(list);
      // انتخاب اولین متقاضی حاضرنشده به عنوان متقاضی جاری
      const pending = list.find((c) => c.status !== 'present');
      if (pending) {
        setSelectedCandidate(pending);
      } else if (list.length > 0) {
        setSelectedCandidate(list[0]);
      }
    } catch (err) {
      notifyError('خطا در بارگذاری اطلاعات متقاضیان.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadCandidates();
  }, [loadCandidates]);

  const handleConfirm = async () => {
    if (!selectedCandidate) return;
    setBusy(true);
    try {
      await api.post(`${API_BASE}/admin/candidates/${selectedCandidate.id}/checkin`);
      notifySuccess(`حضور متقاضی «${selectedCandidate.name}» با موفقیت تأیید شد.`);
      await loadCandidates();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت Check-in متقاضی.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  const filteredCandidates = candidates.filter((c) => {
    if (!search) return true;
    const s = search.toLowerCase();
    return (
      c.name?.toLowerCase().includes(s) ||
      c.candidate_code?.toLowerCase().includes(s) ||
      c.username?.toLowerCase().includes(s)
    );
  });

  return (
    <div className="space-y-4">
      <PageHeader
        title="Check-in و تأیید هویت متقاضیان"
        crumb={['متقاضیان', 'Check-in']}
        actions={
          <button
            type="button"
            onClick={loadCandidates}
            disabled={loading}
            className="btn btn-outline text-xs cursor-pointer"
          >
            {loading ? <Spinner size="sm" /> : '⟳ بازخوانی'}
          </button>
        }
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

      <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
        {/* کارت تایید متقاضی جاری */}
        <div className="card md:col-span-5 space-y-3">
          <div className="font-bold text-navy text-[14px]">تأیید متقاضی جاری</div>
          {selectedCandidate ? (
            <div className="text-[12.5px] leading-relaxed text-ink space-y-1.5">
              <div>
                نام کامل: <b>{selectedCandidate.name}</b>
              </div>
              <div>
                کد متقاضی: <b className="font-mono text-navy">{selectedCandidate.candidate_code}</b>
              </div>
              <div>
                کد ملی / تذکره: <b>{selectedCandidate.national_id || '—'}</b>
              </div>
              <div>
                یوزرنیم ورود: <b className="font-mono">{selectedCandidate.username}</b>
              </div>
              <div className="pt-2 flex items-center gap-2">
                وضعیت:
                <span className={selectedCandidate.status === 'present' ? 'badge on' : 'badge pending'}>
                  {selectedCandidate.status === 'present' ? '✓ حاضر و Checked-in' : 'در انتظار تأیید'}
                </span>
              </div>
              <div className="flex gap-2.5 pt-3">
                <button
                  type="button"
                  onClick={handleConfirm}
                  disabled={busy || selectedCandidate.status === 'present'}
                  className="btn btn-teal text-xs cursor-pointer disabled:opacity-50"
                >
                  {busy ? <Spinner size="sm" /> : '✓ تأیید و Check-in'}
                </button>
                <button
                  type="button"
                  onClick={() => notifyError('گزارش عدم تطابق هویت ثبت گردید.')}
                  className="btn btn-outline text-xs cursor-pointer"
                >
                  عدم تطابق هویت
                </button>
              </div>
            </div>
          ) : (
            <div className="text-xs text-ink-soft py-4 text-center">
              {loading ? 'در حال بارگذاری اطلاعات...' : 'متقاضی‌ای انتخاب نشده است.'}
            </div>
          )}
        </div>

        {/* جدول وضعیت Check-in متقاضیان */}
        <div className="card md:col-span-7 space-y-3">
          <div className="flex items-center justify-between gap-2 flex-wrap">
            <div className="font-bold text-navy text-[14px]">لیست متقاضیان جلسه</div>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="جستجو در کد یا نام..."
              className="rounded border border-line px-2.5 py-1 text-xs max-w-[180px]"
            />
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-right text-xs">
              <thead>
                <tr className="border-b border-line text-ink-soft">
                  <th className="py-2.5 px-3">کد متقاضی</th>
                  <th className="py-2.5 px-3">نام</th>
                  <th className="py-2.5 px-3">وضعیت Check-in</th>
                  <th className="py-2.5 px-3">عملیات</th>
                </tr>
              </thead>
              <tbody>
                {filteredCandidates.map((c) => {
                  const isCur = selectedCandidate?.id === c.id;
                  return (
                    <tr
                      key={c.id}
                      onClick={() => setSelectedCandidate(c)}
                      className={`border-b border-line-soft hover:bg-row-hover cursor-pointer transition-colors ${
                        isCur ? 'bg-navy/5 font-semibold' : ''
                      }`}
                    >
                      <td className="py-2.5 px-3 font-mono font-semibold text-navy">{c.candidate_code}</td>
                      <td className="py-2.5 px-3 font-medium text-ink">{c.name}</td>
                      <td className="py-2.5 px-3">
                        {c.status === 'present' ? (
                          <span className="badge on">✓ Checked-in</span>
                        ) : (
                          <span className="badge pending">در انتظار تأیید</span>
                        )}
                      </td>
                      <td className="py-2.5 px-3">
                        <button
                          type="button"
                          className="text-xs text-gold hover:underline"
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelectedCandidate(c);
                          }}
                        >
                          انتخاب
                        </button>
                      </td>
                    </tr>
                  );
                })}
                {filteredCandidates.length === 0 && !loading && (
                  <tr>
                    <td colSpan={4} className="py-6 text-center text-ink-soft text-xs">
                      هیچ متقاضی‌ای یافت نشد.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
}

export { CandidateCheckinPage };
