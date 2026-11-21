import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select } from '../ui/index.js';

export default function CandidateFormModal({ open, onClose, onSave, candidate = null, busy = false }) {
  const isEditing = Boolean(candidate);

  const [formData, setFormData] = useState({
    name: '',
    national_id: '',
    prefix: 'KND',
    status: 'present',
  });
  const [createdResult, setCreatedResult] = useState(null);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    setCreatedResult(null);
    setErrors({});

    if (candidate) {
      setFormData({
        name: candidate.name || '',
        national_id: candidate.national_id || '',
        prefix: 'KND',
        status: candidate.status || 'present',
      });
    } else {
      setFormData({
        name: '',
        national_id: '',
        prefix: 'KND',
        status: 'present',
      });
    }
  }, [candidate, open]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});

    if (!formData.name?.trim()) {
      setErrors({ name: 'نام کامل متقاضی الزامی است.' });
      return;
    }

    try {
      if (isEditing) {
        // مطابق UpdateCandidateRequest: name, national_id, status
        await onSave({
          name: formData.name.trim(),
          national_id: formData.national_id?.trim() || null,
          status: formData.status,
        });
        onClose();
      } else {
        // مطابق StoreCandidateRequest: name, national_id, prefix
        const res = await onSave({
          name: formData.name.trim(),
          national_id: formData.national_id?.trim() || null,
          prefix: formData.prefix?.trim() || undefined,
        });

        // اگر سرور plain_password و candidate تولیدشده برگرداند، به کاربر نشان می‌دهیم
        if (res && res.plain_password) {
          setCreatedResult(res);
        } else {
          onClose();
        }
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

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEditing ? 'ویرایش متقاضی' : 'افزودن متقاضی جدید'}
      size="md"
    >
      {createdResult ? (
        <div className="space-y-4 py-2">
          <div className="p-3 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-900 text-xs font-bold leading-relaxed">
            ✓ حساب کاربری متقاضی با موفقیت در سرور ایجاد شد. اطلاعات ورود موقت را یادداشت نمایید:
          </div>
          <div className="border border-line rounded-xl p-3.5 bg-paper/60 space-y-2 text-xs">
            <div className="flex justify-between py-1 border-b border-line/60">
              <span className="text-ink-soft">نام متقاضی:</span>
              <span className="font-bold text-navy">{createdResult.candidate?.name}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/60">
              <span className="text-ink-soft">کد متقاضی:</span>
              <span className="font-mono font-bold text-navy">{createdResult.candidate?.candidate_code}</span>
            </div>
            <div className="flex justify-between py-1 border-b border-line/60">
              <span className="text-ink-soft">نام کاربری (Username):</span>
              <span className="font-mono font-bold text-navy">{createdResult.candidate?.username}</span>
            </div>
            <div className="flex justify-between py-1 items-center">
              <span className="text-ink-soft">رمز عبور اولیه:</span>
              <span className="font-mono font-bold text-gold text-sm bg-gold/10 px-2 py-0.5 rounded">
                {createdResult.plain_password}
              </span>
            </div>
          </div>
          <div className="flex justify-end pt-2">
            <Button variant="primary" onClick={onClose}>
              تأیید و بستن
            </Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          {isEditing && (
            <FieldRow cols={2}>
              <Field label="کد متقاضی (تولید سرور)">
                <Input value={candidate.candidate_code || '—'} disabled />
              </Field>
              <Field label="نام کاربری (تولید سرور)">
                <Input value={candidate.username || '—'} disabled />
              </Field>
            </FieldRow>
          )}

          <Field label="نام کامل متقاضی" required error={errors.name}>
            <Input
              value={formData.name}
              onChange={(e) => handleChange('name', e.target.value)}
              placeholder="مثلاً: احمد ولی کریمی"
            />
          </Field>

          <FieldRow cols={2}>
            <Field label="کد ملی / شماره تذکره (اختیاری)" error={errors.national_id}>
              <Input
                value={formData.national_id}
                onChange={(e) => handleChange('national_id', e.target.value)}
                placeholder="۱۲۳۴۵۶۷۸"
              />
            </Field>

            {!isEditing ? (
              <Field label="پیشوند کد (Prefix)" error={errors.prefix}>
                <Input
                  value={formData.prefix}
                  onChange={(e) => handleChange('prefix', e.target.value)}
                  placeholder="KND"
                />
              </Field>
            ) : (
              <Field label="وضعیت متقاضی" error={errors.status}>
                <Select
                  value={formData.status}
                  onChange={(e) => handleChange('status', e.target.value)}
                >
                  <option value="present">حاضر (Present)</option>
                  <option value="absent">غایب (Absent)</option>
                  <option value="late">با تأخیر (Late)</option>
                  <option value="disqualified">محروم (Disqualified)</option>
                </Select>
              </Field>
            )}
          </FieldRow>

          {!isEditing && (
            <div className="text-[11px] text-ink-soft bg-paper/50 p-2.5 rounded-lg border border-line leading-relaxed">
              * کد متقاضی، نام کاربری و رمز عبور امن اولیه توسط سرور به‌صورت خودکار تولید خواهد شد.
            </div>
          )}

          <div className="flex justify-end gap-2 pt-3 border-t border-line">
            <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
              انصراف
            </Button>
            <Button type="submit" variant="primary" loading={busy}>
              {isEditing ? 'ذخیره تغییرات' : 'ایجاد متقاضی'}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
