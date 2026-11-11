import { useState } from 'react';
import { Button, Field, Input, Modal, Select } from '../ui/index.js';

export default function ManualOverrideModal({ open, onClose, onOverride, attempt, busy = false }) {
  const [formData, setFormData] = useState({
    override_type: 'time_extension',
    extra_minutes: 10,
    reason: '',
  });
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onOverride(attempt.id, formData);
      onClose();
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`عملیات دستی ادمین برای ${attempt?.candidate?.name || ''}`}
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="نوع عملیات">
          <Select
            value={formData.override_type}
            onChange={(e) => setFormData((p) => ({ ...p, override_type: e.target.value }))}
          >
            <option value="time_extension">تمدید زمان امتحان (Time Extension)</option>
            <option value="pause">توقف موقت امتحان (Pause)</option>
            <option value="resume">ادامه امتحان متوقف‌شده (Resume)</option>
            <option value="force_submit">خاتمه و ثبت اجباری (Force Submit)</option>
            <option value="disqualify">ابطال و محروم‌سازی (Disqualify)</option>
          </Select>
        </Field>

        {formData.override_type === 'time_extension' && (
          <Field label="دقایق اضافه" required error={errors.extra_minutes}>
            <Input
              type="number"
              min="1"
              max="60"
              value={formData.extra_minutes}
              onChange={(e) => setFormData((p) => ({ ...p, extra_minutes: Number(e.target.value) }))}
            />
          </Field>
        )}

        <Field label="دلیل عملیات دستی (در لاگ حسابرسی ثبت می‌شود)" required error={errors.reason}>
          <Input
            value={formData.reason}
            onChange={(e) => setFormData((p) => ({ ...p, reason: e.target.value }))}
            placeholder="مثلاً: جبران وقفه ناشی از نوسان برق به مدت ۵ دقیقه"
          />
        </Field>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            اعمال دستور
          </Button>
        </div>
      </form>
    </Modal>
  );
}
