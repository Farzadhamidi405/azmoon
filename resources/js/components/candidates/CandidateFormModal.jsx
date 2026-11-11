import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select } from '../ui/index.js';

export default function CandidateFormModal({ open, onClose, onSave, candidate = null, busy = false }) {
  const [formData, setFormData] = useState({
    name: '',
    candidate_code: '',
    national_id: '',
    username: '',
    password: '',
    status: 'registered',
  });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (candidate) {
      setFormData({
        name: candidate.name || '',
        candidate_code: candidate.candidate_code || '',
        national_id: candidate.national_id || '',
        username: candidate.username || '',
        password: '',
        status: candidate.status || 'registered',
      });
    } else {
      const randomCode = 'KND' + Math.floor(100 + Math.random() * 900);
      setFormData({
        name: '',
        candidate_code: randomCode,
        national_id: '',
        username: randomCode.toLowerCase(),
        password: '',
        status: 'registered',
      });
    }
    setErrors({});
  }, [candidate, open]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onSave(formData);
      onClose();
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={candidate ? 'ویرایش متقاضی' : 'افزودن متقاضی جدید'}
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <FieldRow cols={2}>
          <Field label="نام کامل" required error={errors.name}>
            <Input
              value={formData.name}
              onChange={(e) => handleChange('name', e.target.value)}
              placeholder="مثلاً: احمد ولی"
            />
          </Field>
          <Field label="کد ملی / شماره تذکره" error={errors.national_id}>
            <Input
              value={formData.national_id}
              onChange={(e) => handleChange('national_id', e.target.value)}
              placeholder="۱۲۳۴۵۶۷۸"
            />
          </Field>
        </FieldRow>

        <FieldRow cols={2}>
          <Field label="کد متقاضی" required error={errors.candidate_code}>
            <Input
              value={formData.candidate_code}
              onChange={(e) => handleChange('candidate_code', e.target.value)}
              placeholder="KND001"
            />
          </Field>
          <Field label="نام کاربری" required error={errors.username}>
            <Input
              value={formData.username}
              onChange={(e) => handleChange('username', e.target.value)}
              placeholder="knd001"
            />
          </Field>
        </FieldRow>

        <Field label={candidate ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور (اختیاری — پیش‌فرض خودکار)'} error={errors.password}>
          <Input
            type="password"
            value={formData.password}
            onChange={(e) => handleChange('password', e.target.value)}
            placeholder="••••••••"
          />
        </Field>

        <Field label="وضعیت متقاضی">
          <Select
            value={formData.status}
            onChange={(e) => handleChange('status', e.target.value)}
          >
            <option value="registered">ثبت‌نام شده (Registered)</option>
            <option value="checked_in">حاضر در جلسه (Checked-in)</option>
            <option value="in_progress">در حال امتحان (In Progress)</option>
            <option value="completed">تکمیل‌شده (Completed)</option>
            <option value="absent">غایب (Absent)</option>
            <option value="disqualified">محروم / باطل (Disqualified)</option>
          </Select>
        </Field>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            {candidate ? 'ذخیره تغییرات' : 'ایجاد متقاضی'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
