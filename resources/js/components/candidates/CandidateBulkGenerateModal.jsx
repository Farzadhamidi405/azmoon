import { useState } from 'react';
import { Button, Field, FieldRow, Input, Modal } from '../ui/index.js';
import { notifySuccess } from '../../lib/notify.js';

export default function CandidateBulkGenerateModal({ open, onClose, onGenerate, busy = false }) {
  const [formData, setFormData] = useState({
    count: 20,
    prefix: 'KND',
  });
  const [createdList, setCreatedList] = useState([]);
  const [errors, setErrors] = useState({});

  const handleGenerate = async (e) => {
    e.preventDefault();
    setErrors({});

    const countNum = Number(formData.count);
    if (!countNum || countNum < 1) {
      setErrors({ count: 'حداقل ۱ متقاضی باید تولید شود.' });
      return;
    }
    if (countNum > 200) {
      setErrors({ count: 'حداکثر ۲۰۰ متقاضی در یک عملیات مجاز است.' });
      return;
    }

    try {
      const payload = {
        count: countNum,
        prefix: formData.prefix?.trim() || undefined,
      };
      const res = await onGenerate(payload);
      if (res && res.data) {
        setCreatedList(Array.isArray(res.data) ? res.data : res.data.candidates || []);
      }
    } catch (err) {
      if (err.fieldErrors) {
        setErrors(err.fieldErrors);
      } else if (err.response?.data?.errors) {
        const extracted = {};
        Object.entries(err.response.data.errors).forEach(([k, v]) => {
          extracted[k] = Array.isArray(v) ? v[0] : v;
        });
        setErrors(extracted);
      }
    }
  };

  const copyToClipboard = () => {
    if (!createdList.length) return;
    const tsv = ['کد متقاضی\tنام کاربری\tرمز عبور اولیه']
      .concat(
        createdList.map(
          (c) =>
            `${c.candidate?.candidate_code || c.candidate_code || ''}\t${c.candidate?.username || c.username || ''}\t${c.plain_password || c.password || ''}`
        )
      )
      .join('\n');
    navigator.clipboard?.writeText(tsv);
    notifySuccess('اطلاعات متقاضیان در کلیپ‌بورد کپی شد.');
  };

  return (
    <Modal open={open} onClose={onClose} title="تولید گروهی متقاضیان" size="lg">
      {!createdList.length ? (
        <form onSubmit={handleGenerate} className="space-y-4">
          <FieldRow cols={2}>
            <Field label="تعداد متقاضیان (حداکثر ۲۰۰)" required error={errors.count}>
              <Input
                type="number"
                min="1"
                max="200"
                value={formData.count}
                onChange={(e) => setFormData((p) => ({ ...p, count: Number(e.target.value) }))}
              />
            </Field>
            <Field label="پیشوند کد متقاضی" error={errors.prefix}>
              <Input
                value={formData.prefix}
                onChange={(e) => setFormData((p) => ({ ...p, prefix: e.target.value }))}
                placeholder="KND"
              />
            </Field>
          </FieldRow>

          <div className="p-3 rounded-xl bg-gold/10 border border-gold/30 text-ink text-xs leading-relaxed space-y-1">
            <div className="font-bold text-navy">نکته مهم در مورد تخصیص آزمون و شیفت:</div>
            <div>
              کدهای متقاضی، نام‌های کاربری و رمزهای عبور اولیه به‌صورت تصادفی و امن توسط سرور تولید می‌شوند.
              تخصیص متقاضیان به آزمون، شیفت و سیستم کامپیوتری پس از تولید حساب‌ها، از طریق بخش تخصیص شیفت‌ها یا گزینه‌های متقاضیان انجام می‌گردد.
            </div>
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
          <div className="flex items-center justify-between pb-2 border-b border-line">
            <div className="text-sm font-bold text-emerald-800">
              ✓ تعداد {createdList.length} حساب کاربری با موفقیت توسط سرور ایجاد شد.
            </div>
            <Button size="sm" variant="outline" onClick={copyToClipboard}>
              📋 کپی در کلیپ‌بورد
            </Button>
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
                {createdList.map((item, i) => {
                  const cand = item.candidate || item;
                  const pass = item.plain_password || item.password || '••••••';
                  return (
                    <tr key={i} className="border-b border-line/50 hover:bg-paper/40">
                      <td className="p-2 font-mono font-bold">{cand.candidate_code || cand.code}</td>
                      <td className="p-2 font-mono">{cand.username}</td>
                      <td className="p-2 font-mono text-gold font-bold">{pass}</td>
                    </tr>
                  );
                })}
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
