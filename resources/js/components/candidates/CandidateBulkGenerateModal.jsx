import { useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select } from '../ui/index.js';
import useExams from '../../hooks/useExams.js';
import useShifts from '../../hooks/useShifts.js';

export default function CandidateBulkGenerateModal({ open, onClose, onGenerate, busy = false }) {
  const { items: exams } = useExams();
  const { items: shifts } = useShifts();
  const [formData, setFormData] = useState({
    count: 20,
    exam_id: '',
    shift_id: '',
    prefix: 'KND',
  });
  const [createdList, setCreatedList] = useState([]);
  const [errors, setErrors] = useState({});

  const handleGenerate = async (e) => {
    e.preventDefault();
    try {
      const res = await onGenerate(formData);
      if (res && res.data) {
        setCreatedList(Array.isArray(res.data) ? res.data : res.data.candidates || []);
      }
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="تولید گروهی متقاضیان" size="lg">
      {!createdList.length ? (
        <form onSubmit={handleGenerate} className="space-y-4">
          <FieldRow cols={2}>
            <Field label="تعداد حساب‌ها" required error={errors.count}>
              <Input
                type="number"
                min="1"
                max="500"
                value={formData.count}
                onChange={(e) => setFormData((p) => ({ ...p, count: Number(e.target.value) }))}
              />
            </Field>
            <Field label="پیشوند کد متقاضی">
              <Input
                value={formData.prefix}
                onChange={(e) => setFormData((p) => ({ ...p, prefix: e.target.value }))}
                placeholder="KND"
              />
            </Field>
          </FieldRow>

          <FieldRow cols={2}>
            <Field label="آزمون تخصیص‌یافته" error={errors.exam_id}>
              <Select
                value={formData.exam_id}
                onChange={(e) => setFormData((p) => ({ ...p, exam_id: e.target.value }))}
              >
                <option value="">انتخاب آزمون (اختیاری)...</option>
                {exams.map((ex) => (
                  <option key={ex.id} value={ex.id}>
                    {ex.title}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label="شفت زمانی" error={errors.shift_id}>
              <Select
                value={formData.shift_id}
                onChange={(e) => setFormData((p) => ({ ...p, shift_id: e.target.value }))}
              >
                <option value="">انتخاب شفت (اختیاری)...</option>
                {shifts.map((sh) => (
                  <option key={sh.id} value={sh.id}>
                    {sh.name} ({sh.lab?.name || 'لابراتوار'})
                  </option>
                ))}
              </Select>
            </Field>
          </FieldRow>

          <div className="text-xs text-ink-soft bg-paper/60 p-3 rounded-lg leading-relaxed">
            کد متقاضی، نام کاربری و رمزهای عبور موقت به‌صورت تصادفی و امن ایجاد خواهند شد. اطلاعات ورود پس از تولید قابل دانلود و چاپ کارت هستند.
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-line">
            <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
              انصراف
            </Button>
            <Button type="submit" variant="primary" loading={busy}>
              تولید {formData.count} حساب کاربری
            </Button>
          </div>
        </form>
      ) : (
        <div className="space-y-4">
          <div className="text-sm font-bold text-teal">
            ✓ تعداد {createdList.length} حساب کاربری با موفقیت تولید شد.
          </div>
          <div className="max-h-[300px] overflow-y-auto border border-line rounded-lg">
            <table className="w-full text-xs">
              <thead className="bg-paper border-b border-line sticky top-0">
                <tr>
                  <th className="p-2 text-right">کد متقاضی</th>
                  <th className="p-2 text-right">نام کاربری</th>
                  <th className="p-2 text-right">رمز عبور اولیه</th>
                </tr>
              </thead>
              <tbody>
                {createdList.map((cand, i) => (
                  <tr key={i} className="border-b border-line/50">
                    <td className="p-2 font-mono font-bold">{cand.candidate_code || cand.code}</td>
                    <td className="p-2 font-mono">{cand.username}</td>
                    <td className="p-2 font-mono text-gold">{cand.plain_password || cand.password || '••••••'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex justify-end">
            <Button variant="primary" onClick={onClose}>
              بستن
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
