import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Modal, Select, Spinner } from '../ui/index.js';
import { api, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function CandidateAssignModal({
  open,
  onClose,
  candidate = null,
  allCandidates = [],
  onAssigned,
}) {
  const [selectedCandidateId, setSelectedCandidateId] = useState('');
  const [exams, setExams] = useState([]);
  const [shifts, setShifts] = useState([]);
  const [selectedExamId, setSelectedExamId] = useState('');
  const [selectedShiftId, setSelectedShiftId] = useState('');
  const [shiftMeta, setShiftMeta] = useState(null);
  const [selectedComputerId, setSelectedComputerId] = useState('');

  const [loadingExams, setLoadingExams] = useState(false);
  const [loadingShifts, setLoadingShifts] = useState(false);
  const [loadingMeta, setLoadingMeta] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  // مقداردهی اولیه هنگام باز شدن مودال
  useEffect(() => {
    if (!open) {
      setFormError('');
      setShiftMeta(null);
      setSelectedShiftId('');
      setSelectedComputerId('');
      return;
    }

    if (candidate) {
      setSelectedCandidateId(String(candidate.id));
      const prevAssign = candidate.latest_assignment || candidate.latestAssignment;
      if (prevAssign) {
        setSelectedExamId(prevAssign.exam_id ? String(prevAssign.exam_id) : '');
      } else {
        setSelectedExamId('');
      }
    } else {
      setSelectedCandidateId(allCandidates[0]?.id ? String(allCandidates[0].id) : '');
      setSelectedExamId('');
    }

    // دریافت آزمون‌های منتشرشده (Published)
    setLoadingExams(true);
    api
      .get('/api/v1/admin/exams', { params: { per_page: 100, status: 'published' } })
      .then((res) => {
        const list = unwrapList(res.data).items || [];
        setExams(list);
      })
      .catch(() => {
        // در صورت عدم فیلتر سروری، آزمون‌های غیر Draft
        api
          .get('/api/v1/admin/exams', { params: { per_page: 100 } })
          .then((res) => {
            const list = (unwrapList(res.data).items || []).filter((e) =>
              ['published', 'running'].includes(e.status)
            );
            setExams(list);
          })
          .catch(() => setExams([]));
      })
      .finally(() => setLoadingExams(false));
  }, [open, candidate]);

  // وقتی آزمون انتخاب می‌شود، شیفت‌های آن واکشی می‌شوند
  useEffect(() => {
    if (!selectedExamId) {
      setShifts([]);
      setSelectedShiftId('');
      setShiftMeta(null);
      return;
    }

    setLoadingShifts(true);
    setShiftMeta(null);
    setSelectedShiftId('');
    setSelectedComputerId('');

    api
      .get('/api/v1/admin/shifts', { params: { exam_id: selectedExamId } })
      .then((res) => {
        const list = res.data?.data || [];
        setShifts(list);
        if (list.length === 1) {
          setSelectedShiftId(String(list[0].id));
        }
      })
      .catch(() => {
        setShifts([]);
      })
      .finally(() => setLoadingShifts(false));
  }, [selectedExamId]);

  // وقتی شیفت انتخاب می‌شود، متای شیفت و وضعیت کامپیوترها دریافت می‌شود
  useEffect(() => {
    if (!selectedShiftId) {
      setShiftMeta(null);
      setSelectedComputerId('');
      return;
    }

    setLoadingMeta(true);
    api
      .get(`/api/v1/admin/shifts/${selectedShiftId}/assignment-meta`)
      .then((res) => {
        setShiftMeta(res.data?.data || null);
      })
      .catch(() => {
        setShiftMeta(null);
      })
      .finally(() => setLoadingMeta(false));
  }, [selectedShiftId]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setFormError('');

    const targetCandidateId = candidate?.id || selectedCandidateId;
    if (!targetCandidateId) {
      setFormError('لطفاً متقاضی را انتخاب کنید.');
      return;
    }
    if (!selectedExamId) {
      setFormError('انتخاب آزمون الزامی است.');
      return;
    }
    if (!selectedShiftId) {
      setFormError('انتخاب نوبت (شیفت) الزامی است.');
      return;
    }
    const labId = shiftMeta?.lab?.id || shifts.find((s) => String(s.id) === String(selectedShiftId))?.lab_id;
    if (!labId) {
      setFormError('لابراتوار متصل به این شیفت یافت نشد.');
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        exam_id: Number(selectedExamId),
        shift_id: Number(selectedShiftId),
        lab_id: Number(labId),
        computer_id: selectedComputerId ? Number(selectedComputerId) : null,
      };

      const res = await api.post(`/api/v1/admin/candidates/${targetCandidateId}/assign`, payload);
      notifySuccess(res.data?.message || 'تخصیص آزمون با موفقیت انجام شد.');
      if (typeof onAssigned === 'function') {
        onAssigned(res.data?.data);
      }
      onClose();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت تخصیص آزمون.';
      setFormError(msg);
      notifyError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const activeCandidate = candidate || allCandidates.find((c) => String(c.id) === String(selectedCandidateId));
  const currentShift = shifts.find((s) => String(s.id) === String(selectedShiftId));
  const remainingCap = shiftMeta?.shift?.remaining_capacity ?? (currentShift?.capacity ? currentShift.capacity : '—');
  const isShiftFull = shiftMeta?.shift?.capacity && shiftMeta?.shift?.remaining_capacity <= 0;

  return (
    <Modal open={open} onClose={onClose} title="تخصیص متقاضی به آزمون و صندلی" size="lg">
      <form onSubmit={handleSubmit} className="space-y-4 font-vazir text-xs">
        {formError && (
          <div className="p-3 bg-red-50 border border-red-300 rounded-xl text-red-800 font-semibold leading-relaxed">
            {formError}
          </div>
        )}

        {/* مشخصات متقاضی */}
        <div className="card p-3 bg-paper/60 border border-line space-y-1">
          <div className="text-[11px] text-ink-soft font-semibold">متقاضی انتخاب‌شده:</div>
          {candidate ? (
            <div className="flex items-center justify-between">
              <span className="font-bold text-navy text-sm">{candidate.name}</span>
              <span className="font-mono text-xs bg-panel px-2 py-0.5 rounded border border-line">
                کد داوطلبی: <b>{candidate.candidate_code}</b>
              </span>
            </div>
          ) : (
            <Select
              value={selectedCandidateId}
              onChange={(e) => setSelectedCandidateId(e.target.value)}
              className="mt-1"
            >
              <option value="">-- انتخاب متقاضی از لیست --</option>
              {allCandidates.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.candidate_code})
                </option>
              ))}
            </Select>
          )}
        </div>

        {/* انتخاب آزمون */}
        <Field label="آزمون (فقط آزمون‌های منتشرشده)" required>
          <div className="relative">
            <Select
              value={selectedExamId}
              onChange={(e) => setSelectedExamId(e.target.value)}
              disabled={loadingExams}
            >
              <option value="">-- لطفاً آزمون را انتخاب کنید --</option>
              {exams.map((ex) => (
                <option key={ex.id} value={ex.id}>
                  {ex.title} ({ex.duration_minutes} دقیقه)
                </option>
              ))}
            </Select>
            {loadingExams && (
              <span className="absolute left-3 top-2.5">
                <Spinner size="sm" />
              </span>
            )}
          </div>
        </Field>

        {/* انتخاب نوبت آزمون (Shift) */}
        {selectedExamId && (
          <Field label="نوبت آزمون (شیفت)" required>
            <div className="relative">
              <Select
                value={selectedShiftId}
                onChange={(e) => setSelectedShiftId(e.target.value)}
                disabled={loadingShifts || shifts.length === 0}
              >
                <option value="">-- لطفاً شیفت آزمون را انتخاب کنید --</option>
                {shifts.map((sh) => (
                  <option key={sh.id} value={sh.id}>
                    {sh.name} — {sh.lab?.name || 'لابراتوار اختصاصی'} (ظرفیت: {sh.capacity ?? 'نامحدود'} نفر)
                  </option>
                ))}
              </Select>
              {loadingShifts && (
                <span className="absolute left-3 top-2.5">
                  <Spinner size="sm" />
                </span>
              )}
            </div>
            {shifts.length === 0 && !loadingShifts && (
              <span className="text-[11px] text-red-600 mt-1 block">
                هیچ شیفتی برای این آزمون ثبت نشده است. ابتدا در بخش شیفت‌ها نوبت ایجاد نمایید.
              </span>
            )}
          </Field>
        )}

        {/* وضعیت ظرفیت و لابراتوار */}
        {selectedShiftId && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 bg-panel-2 rounded-xl border border-line">
            <div>
              <span className="text-ink-soft">محل آزمون (لابراتوار): </span>
              <b className="text-navy">{shiftMeta?.lab?.name || currentShift?.lab?.name || 'لابراتوار مرکزی'}</b>
            </div>
            <div>
              <span className="text-ink-soft">وضعیت ظرفیت شیفت: </span>
              {loadingMeta ? (
                <span>در حال استعلام...</span>
              ) : isShiftFull ? (
                <b className="text-red">تکمیل ظرفیت ({shiftMeta?.shift?.capacity} نفر)</b>
              ) : (
                <b className="text-teal font-mono">
                  {shiftMeta?.shift?.assigned_count ?? 0} از {shiftMeta?.shift?.capacity ?? '—'} نفر (باقی‌مانده: {remainingCap})
                </b>
              )}
            </div>
          </div>
        )}

        {/* انتخاب صندلی / کامپیوتر */}
        {selectedShiftId && (
          <Field label="انتخاب صندلی / کامپیوتر (اختیاری یا تخصیص دستی)">
            <div className="relative">
              <Select
                value={selectedComputerId}
                onChange={(e) => setSelectedComputerId(e.target.value)}
                disabled={loadingMeta}
              >
                <option value="">-- بدون تعیین کامپیوتر (تخصیص خودکار در لابراتوار) --</option>
                {(shiftMeta?.computers || []).map((comp) => {
                  const isCurCandidate =
                    activeCandidate && comp.occupied_by?.candidate_id === activeCandidate.id;
                  const isBlocked = comp.is_occupied && !isCurCandidate;
                  return (
                    <option
                      key={comp.id}
                      value={comp.id}
                      disabled={isBlocked}
                      className={isBlocked ? 'text-gray-400 bg-gray-50' : ''}
                    >
                      {comp.computer_code} ({comp.ip_address})
                      {isBlocked ? ` — [اشغال توسط ${comp.occupied_by?.candidate_name || 'متقاضی دیگر'}]` : ''}
                      {isCurCandidate ? ' — [تخصیص فعلی این متقاضی]' : ''}
                    </option>
                  );
                })}
              </Select>
              {loadingMeta && (
                <span className="absolute left-3 top-2.5">
                  <Spinner size="sm" />
                </span>
              )}
            </div>
            <div className="text-[11px] text-ink-soft mt-1">
              در صورت خالی گذاشتن، صندلی متقاضی از طریق بخش تخصیص خودکار شفت (Auto-assign) تعیین خواهد شد.
            </div>
          </Field>
        )}

        {/* دکمه‌های فرم */}
        <div className="flex items-center justify-between pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            انصراف
          </Button>
          <Button
            type="submit"
            variant="gold"
            loading={submitting}
            disabled={!selectedExamId || !selectedShiftId || isShiftFull}
          >
            ✓ ثبت تخصیص آزمون
          </Button>
        </div>
      </form>
    </Modal>
  );
}
