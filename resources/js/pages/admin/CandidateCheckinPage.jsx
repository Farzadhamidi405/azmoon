import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge, Button, Modal, PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function CandidateCheckinPage() {
  const [candidates, setCandidates] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [selectedCandidate, setSelectedCandidate] = useState(null);
  const [search, setSearch] = useState('');

  // استیت‌های مودال رد هویت
  const [rejectModalOpen, setRejectModalOpen] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [rejecting, setRejecting] = useState(false);

  // استیت مودال استعلام با توکن QR
  const [qrModalOpen, setQrModalOpen] = useState(false);
  const [scannedToken, setScannedToken] = useState('');
  const [verifyingQr, setVerifyingQr] = useState(false);

  // بارگذاری لیست متقاضیان با روابط کامل
  const loadCandidates = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/candidates`, {
        params: { per_page: 100 },
      });
      const list = unwrapList(res.data).items || [];
      setCandidates(list);

      // اگر متقاضی جاری در لیست موجود بود، اطلاعاتش را به‌روزرسانی کن
      if (selectedCandidate) {
        const updated = list.find((c) => c.id === selectedCandidate.id);
        if (updated) setSelectedCandidate(updated);
      } else {
        const pending = list.find((c) => c.status !== 'present' && c.status !== 'disqualified');
        if (pending) setSelectedCandidate(pending);
        else if (list.length > 0) setSelectedCandidate(list[0]);
      }
    } catch {
      notifyError('خطا در بارگذاری اطلاعات متقاضیان.');
    } finally {
      setLoading(false);
    }
  }, [selectedCandidate?.id]);

  useEffect(() => {
    loadCandidates();
  }, []);

  // ۱. عملیات تأیید هویت و ثبت Check-in واقعی
  const handleConfirmCheckin = async () => {
    if (!selectedCandidate) return;

    const assignment = selectedCandidate.latest_assignment || selectedCandidate.latestAssignment;
    if (!assignment) {
      notifyError('این متقاضی هنوز به هیچ آزمون/شیفتی تخصیص نیافته است و امکان Check-in وجود ندارد.');
      return;
    }

    if (selectedCandidate.status === 'disqualified') {
      notifyError('هویت این متقاضی قبلاً رد و پرونده او محروم شده است.');
      return;
    }

    setBusy(true);
    try {
      // ابتدا تأیید هویت در سرور ثبت می‌شود
      await api.post(`${API_BASE}/admin/candidates/${selectedCandidate.id}/verify-identity`);
      // سپس ثبت حضور Check-in
      const res = await api.post(`${API_BASE}/admin/candidates/${selectedCandidate.id}/checkin`);
      notifySuccess(res.data?.message || `حضور متقاضی «${selectedCandidate.name}» با موفقیت تأیید شد.`);
      await loadCandidates();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت Check-in متقاضی.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  // ۲. عملیات رد هویت واقعی با ثبت اجباری دلیل در سرور و تغییر به disqualified
  const handleRejectIdentitySubmit = async (e) => {
    e.preventDefault();
    if (!selectedCandidate) return;
    if (!rejectionReason.trim() || rejectionReason.trim().length < 5) {
      notifyError('لطفاً دلیل موجه و کامل برای رد هویت متقاضی وارد نمایید (حداقل ۵ کاراکتر).');
      return;
    }

    setRejecting(true);
    try {
      const res = await api.post(`${API_BASE}/admin/candidates/${selectedCandidate.id}/reject-identity`, {
        reason: rejectionReason.trim(),
      });
      notifySuccess(res.data?.message || 'رد هویت با موفقیت در سیستم ثبت گردید و متقاضی محروم شد.');
      setRejectModalOpen(false);
      setRejectionReason('');
      await loadCandidates();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت رد هویت.';
      notifyError(msg);
    } finally {
      setRejecting(false);
    }
  };

  // ۳. استعلام و اسکن QR Token کارت ورود
  const handleScanQrSubmit = async (e) => {
    e.preventDefault();
    if (!scannedToken.trim()) return;

    setVerifyingQr(true);
    try {
      const res = await api.post(`${API_BASE}/admin/candidates/verify-qr`, {
        qr_token: scannedToken.trim(),
      });
      const data = res.data?.data;
      if (data && data.candidate) {
        notifySuccess(`کارت ورود معتبر است: متقاضی «${data.candidate.name}» شناسایی شد.`);
        // انتخاب متقاضی شناسایی‌شده در پنل بازبینی
        const match = candidates.find((c) => c.id === data.candidate.id) || data.candidate;
        setSelectedCandidate(match);
        setQrModalOpen(false);
        setScannedToken('');
      }
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'کارت ورود یا توکن QR نامعتبر است.';
      notifyError(msg);
    } finally {
      setVerifyingQr(false);
    }
  };

  // جستجو در نام، کد متقاضی، نام کاربری یا تذکره
  const filteredCandidates = candidates.filter((c) => {
    if (!search.trim()) return true;
    const s = search.trim().toLowerCase();
    return (
      c.name?.toLowerCase().includes(s) ||
      c.candidate_code?.toLowerCase().includes(s) ||
      c.username?.toLowerCase().includes(s) ||
      (c.national_id && String(c.national_id).includes(s))
    );
  });

  const assignment = selectedCandidate?.latest_assignment || selectedCandidate?.latestAssignment;
  const isCheckedIn = selectedCandidate?.status === 'present' || selectedCandidate?.checked_in_at !== null;
  const isDisqualified = selectedCandidate?.status === 'disqualified';

  return (
    <div className="space-y-4 font-vazir text-xs">
      <PageHeader
        title="Check-in و تأیید هویت داوطلبان آزمون"
        crumb={['پوهنتون کندز', 'متقاضیان', 'تأیید هویت و Check-in']}
        actions={
          <div className="flex items-center gap-2 flex-wrap">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setQrModalOpen(true)}
              title="اسکن بارکد یا ورود توکن کارت ورود"
            >
              📷 استعلام با QR Code
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={loadCandidates}
              disabled={loading}
            >
              {loading ? <Spinner size="sm" /> : '⟳ بازخوانی'}
            </Button>
            <Link to="/admin/candidates/cards">
              <Button variant="outline" size="sm">
                🪪 کارت‌های ورود
              </Button>
            </Link>
          </div>
        }
      />

      {/* استپر روند ورود به جلسه مطابق Prototype اصلی */}
      <div className="card p-3 flex items-center justify-between gap-2 overflow-x-auto text-[11px] font-semibold text-ink-soft bg-paper/40">
        <div className="flex items-center gap-2 shrink-0">
          <span className="size-5 rounded-full bg-navy text-white flex items-center justify-center text-[10px]">۱</span>
          <span>ورود به سالن آزمون</span>
        </div>
        <span className="text-line">←</span>
        <div className="flex items-center gap-2 shrink-0 text-navy font-bold">
          <span className="size-5 rounded-full bg-gold text-white flex items-center justify-center text-[10px]">۲</span>
          <span className="underline decoration-gold underline-offset-4">تطابق هویت و مدارک (جاری)</span>
        </div>
        <span className="text-line">←</span>
        <div className="flex items-center gap-2 shrink-0">
          <span className="size-5 rounded-full bg-teal text-white flex items-center justify-center text-[10px]">۳</span>
          <span>ثبت حضور (Check-in)</span>
        </div>
        <span className="text-line">←</span>
        <div className="flex items-center gap-2 shrink-0">
          <span className="size-5 rounded-full bg-panel text-ink border border-line flex items-center justify-center text-[10px]">۴</span>
          <span>استقرار در کامپیوتر اختصاصی</span>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
        {/* ستون راست: پرونده متقاضی انتخابی و عملیات تأیید / رد هویت */}
        <div className="card lg:col-span-6 space-y-4 p-5 border-2 border-navy/20">
          <div className="flex items-center justify-between border-b border-line pb-3">
            <div className="font-bold text-navy text-sm flex items-center gap-2">
              <span>👤</span>
              <span>بازبینی هویت و مدارک متقاضی جاری</span>
            </div>
            {selectedCandidate && (
              <Badge
                tone={
                  isDisqualified
                    ? 'bad'
                    : isCheckedIn
                    ? 'teal'
                    : assignment
                    ? 'pending'
                    : 'off'
                }
              >
                {isDisqualified
                  ? 'محروم / رد هویت'
                  : isCheckedIn
                  ? 'حاضر و Checked-in'
                  : assignment
                  ? 'در انتظار تأیید هویت'
                  : 'فاقد تخصیص آزمون'}
              </Badge>
            )}
          </div>

          {selectedCandidate ? (
            <div className="space-y-4">
              {/* خلاصه هویت */}
              <div className="p-3 bg-paper rounded-xl border border-line flex items-center gap-3">
                <div className="size-12 rounded-xl bg-navy text-white flex items-center justify-center font-bold text-lg shrink-0">
                  {selectedCandidate.name?.charAt(0) || 'م'}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="font-extrabold text-navy text-sm truncate">{selectedCandidate.name}</div>
                  <div className="text-[11px] text-ink-soft font-mono flex items-center gap-2 mt-0.5">
                    <span>کد داوطلبی: <b className="text-navy">{selectedCandidate.candidate_code}</b></span>
                    <span>·</span>
                    <span>تذکره: <b>{selectedCandidate.national_id || 'ثبت نشده'}</b></span>
                  </div>
                </div>
              </div>

              {/* مشخصات آزمون، شیفت و صندلی */}
              <div className="space-y-2 border border-line rounded-xl p-3 bg-panel">
                <div className="font-bold text-navy text-xs border-b border-line pb-1.5 flex justify-between">
                  <span>مشخصات تخصیص جلسه آزمون</span>
                  {assignment ? (
                    <span className="text-teal font-semibold">✓ صندلی تعیین‌شده</span>
                  ) : (
                    <span className="text-red font-semibold">⚠️ بدون تخصیص</span>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-2 text-xs">
                  <div>
                    <span className="text-ink-soft">آزمون: </span>
                    <b className="text-navy line-clamp-1">{assignment?.exam?.title || 'تخصیص نیافته'}</b>
                  </div>
                  <div>
                    <span className="text-ink-soft">شیفت زمانی: </span>
                    <b className="text-navy">{assignment?.shift?.name || '—'}</b>
                  </div>
                  <div>
                    <span className="text-ink-soft">محل (لابراتوار): </span>
                    <b className="text-navy">{assignment?.lab?.name || '—'}</b>
                  </div>
                  <div>
                    <span className="text-ink-soft">کامپیوتر / صندلی: </span>
                    <b className="text-teal font-mono font-bold text-sm">
                      {assignment?.computer?.computer_code || 'تعیین نشده'}
                    </b>
                  </div>
                </div>

                {selectedCandidate.checked_in_at && (
                  <div className="pt-2 border-t border-line text-[11px] text-teal">
                    ✓ زمان ثبت حضور در سرور: <b>{new Date(selectedCandidate.checked_in_at).toLocaleTimeString('fa-IR')}</b>
                  </div>
                )}
              </div>

              {/* دکمه‌های عملیات رسمی */}
              <div className="pt-3 border-t border-line flex flex-col sm:flex-row items-center justify-between gap-2">
                <Button
                  variant="outline"
                  size="md"
                  className="text-red hover:bg-red/10 border-red/40 w-full sm:w-auto"
                  onClick={() => setRejectModalOpen(true)}
                  disabled={busy || isDisqualified}
                  title="رد هویت و محروم‌سازی متقاضی با ثبت دلیل"
                >
                  ✕ رد هویت و ثبت تخلف
                </Button>

                <Button
                  variant="teal"
                  size="md"
                  onClick={handleConfirmCheckin}
                  disabled={busy || isCheckedIn || isDisqualified || !assignment}
                  loading={busy}
                  className="w-full sm:w-auto"
                  title={
                    !assignment
                      ? 'متقاضی فاقد تخصیص آزمون است'
                      : isCheckedIn
                      ? 'قبلاً تأیید و چک‌این شده است'
                      : 'تأیید مدارک و ثبت حضور در سرور'
                  }
                >
                  ✓ تأیید هویت و ثبت Check-in
                </Button>
              </div>
            </div>
          ) : (
            <div className="text-center py-12 text-ink-soft">
              {loading ? <Spinner size="md" /> : 'متقاضی‌ای انتخاب نشده است.'}
            </div>
          )}
        </div>

        {/* ستون چپ: لیست و جستجوی چندمعیاره متقاضیان */}
        <div className="card lg:col-span-6 space-y-3 p-5">
          <div className="flex items-center justify-between gap-2 flex-wrap border-b border-line pb-3">
            <div className="font-bold text-navy text-sm">لیست متقاضیان جلسه</div>
            <div className="text-[11px] text-ink-soft font-mono">
              {filteredCandidates.length} متقاضی
            </div>
          </div>

          {/* فیلد جستجوی چندمعیاره */}
          <div>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="جستجو با کد داوطلبی، نام، یوزرنیم یا تذکره..."
              className="w-full rounded-xl border border-line px-3 py-2 text-xs focus:ring-1 focus:ring-navy outline-none"
            />
          </div>

          {/* جدول فشرده با انتخاب آسان */}
          <div className="max-h-[480px] overflow-y-auto border border-line rounded-xl">
            <table className="w-full text-right text-xs">
              <thead className="bg-paper border-b border-line sticky top-0">
                <tr>
                  <th className="p-2.5">کد</th>
                  <th className="p-2.5">نام متقاضی</th>
                  <th className="p-2.5">صندلی</th>
                  <th className="p-2.5">وضعیت</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {filteredCandidates.map((c) => {
                  const isCur = selectedCandidate?.id === c.id;
                  const cAssign = c.latest_assignment || c.latestAssignment;
                  const isPresent = c.status === 'present' || c.checked_in_at !== null;
                  const isDisq = c.status === 'disqualified';

                  return (
                    <tr
                      key={c.id}
                      onClick={() => setSelectedCandidate(c)}
                      className={`hover:bg-row-hover cursor-pointer transition-colors ${
                        isCur ? 'bg-navy/10 font-bold' : ''
                      }`}
                    >
                      <td className="p-2.5 font-mono text-navy font-semibold">{c.candidate_code}</td>
                      <td className="p-2.5 text-ink">{c.name}</td>
                      <td className="p-2.5 font-mono text-[11px] text-ink-soft">
                        {cAssign?.computer?.computer_code || '—'}
                      </td>
                      <td className="p-2.5">
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                            isDisq
                              ? 'bg-red/10 text-red'
                              : isPresent
                              ? 'bg-teal/10 text-teal'
                              : 'bg-amber/10 text-amber'
                          }`}
                        >
                          {isDisq ? 'محروم' : isPresent ? '✓ حاضر' : 'در انتظار'}
                        </span>
                      </td>
                    </tr>
                  );
                })}

                {filteredCandidates.length === 0 && !loading && (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-ink-soft text-xs">
                      هیچ متقاضی‌ای با این مشخصات یافت نشد.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* مودال ثبت رسمی رد هویت و تخلف */}
      <Modal
        open={rejectModalOpen}
        onClose={() => {
          setRejectModalOpen(false);
          setRejectionReason('');
        }}
        title="رد هویت متقاضی و ثبت تخلف در سرور"
        size="md"
      >
        <form onSubmit={handleRejectIdentitySubmit} className="space-y-4 font-vazir text-xs">
          <div className="p-3 bg-red-50 border border-red-300 rounded-xl text-red-900 leading-relaxed space-y-1">
            <div className="font-bold text-sm">⚠️ هشدار محرومیت متقاضی:</div>
            <div>
              با تأیید این فرم، وضعیت متقاضی <b>«{selectedCandidate?.name}»</b> به محروم (Disqualified) تغییر یافته و لاگ ممیزی و رویداد تخلف با نام شما ثبت خواهد شد.
            </div>
          </div>

          <div>
            <label className="block text-ink font-bold mb-1">
              دلیل رد هویت و توضیحات ناظر <span className="text-red">*</span>:
            </label>
            <textarea
              rows={3}
              value={rejectionReason}
              onChange={(e) => setRejectionReason(e.target.value)}
              placeholder="مثلاً: عدم تطابق چهره متقاضی با تصویر کارت ملی / به همراه داشتن شناسنامه جعلی / ادعای شرکت به جای شخص دیگر..."
              className="w-full rounded-xl border border-line p-2.5 text-xs focus:ring-1 focus:ring-red outline-none"
              required
            />
            <div className="text-[11px] text-ink-soft mt-1">حداقل ۵ کاراکتر الزامی است.</div>
          </div>

          <div className="flex justify-between items-center pt-3 border-t border-line">
            <Button
              type="button"
              variant="outline"
              onClick={() => setRejectModalOpen(false)}
              disabled={rejecting}
            >
              انصراف
            </Button>
            <Button
              type="submit"
              variant="outline"
              className="bg-red text-white hover:bg-red/90 border-transparent font-bold"
              loading={rejecting}
              disabled={!rejectionReason.trim()}
            >
              ✕ ثبت قطعی رد هویت و محرومیت
            </Button>
          </div>
        </form>
      </Modal>

      {/* مودال استعلام و اسکن QR Code کارت ورود */}
      <Modal
        open={qrModalOpen}
        onClose={() => {
          setQrModalOpen(false);
          setScannedToken('');
        }}
        title="استعلام و اعتبارسنجی QR Code کارت ورود"
        size="md"
      >
        <form onSubmit={handleScanQrSubmit} className="space-y-4 font-vazir text-xs">
          <div className="p-3 bg-paper rounded-xl border border-line text-ink leading-relaxed">
            توکن تصادفی ۶۴ کاراکتری مندرج در بارکد کارت ورود را وارد کرده یا بارکدخوان را مستقیماً مقابل فیلد قرار دهید:
          </div>

          <div>
            <label className="block text-ink font-semibold mb-1">توکن QR کارت ورود:</label>
            <input
              type="text"
              value={scannedToken}
              onChange={(e) => setScannedToken(e.target.value)}
              placeholder="مثلاً: a4f9e1c8... (یا اسکن بارکد)"
              className="w-full rounded-xl border border-line px-3 py-2 text-xs font-mono outline-none focus:ring-1 focus:ring-navy"
              autoFocus
            />
          </div>

          <div className="flex justify-between items-center pt-3 border-t border-line">
            <Button
              type="button"
              variant="outline"
              onClick={() => setQrModalOpen(false)}
              disabled={verifyingQr}
            >
              بستن
            </Button>
            <Button
              type="submit"
              variant="primary"
              loading={verifyingQr}
              disabled={!scannedToken.trim()}
            >
              🔍 استعلام کارت ورود
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}

export { CandidateCheckinPage };
