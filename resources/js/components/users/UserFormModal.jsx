import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select } from '../ui/index.js';

export default function UserFormModal({ open, onClose, onSave, user = null, busy = false }) {
  const [formData, setFormData] = useState({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'Proctor',
    status: 'active',
  });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (user) {
      setFormData({
        name: user.name || '',
        username: user.username || '',
        email: user.email || '',
        password: '',
        role: user.roles?.[0]?.name || user.role || 'Proctor',
        status: user.status || (user.is_active ? 'active' : 'inactive'),
      });
    } else {
      setFormData({
        name: '',
        username: '',
        email: '',
        password: '',
        role: 'Proctor',
        status: 'active',
      });
    }
    setErrors({});
  }, [user, open]);

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
      title={user ? 'ویرایش کاربر سامانه' : 'تعریف کاربر جدید'}
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <FieldRow cols={2}>
          <Field label="نام و تخلص" required error={errors.name}>
            <Input
              value={formData.name}
              onChange={(e) => setFormData((p) => ({ ...p, name: e.target.value }))}
              placeholder="مثلاً: احمد رضایی"
            />
          </Field>
          <Field label="نام کاربری" required error={errors.username}>
            <Input
              value={formData.username}
              onChange={(e) => setFormData((p) => ({ ...p, username: e.target.value }))}
              placeholder="ahmad_r"
            />
          </Field>
        </FieldRow>

        <FieldRow cols={2}>
          <Field label="ایمیل" error={errors.email}>
            <Input
              type="email"
              value={formData.email}
              onChange={(e) => setFormData((p) => ({ ...p, email: e.target.value }))}
              placeholder="user@kunduz.edu.af"
            />
          </Field>
          <Field label={user ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور'} required={!user} error={errors.password}>
            <Input
              type="password"
              value={formData.password}
              onChange={(e) => setFormData((p) => ({ ...p, password: e.target.value }))}
              placeholder="••••••••"
            />
          </Field>
        </FieldRow>

        <FieldRow cols={2}>
          <Field label="نقش کاربری (Role)">
            <Select
              value={formData.role}
              onChange={(e) => setFormData((p) => ({ ...p, role: e.target.value }))}
            >
              <option value="Super Admin">مدیر کل (Super Admin)</option>
              <option value="Exam Administrator">مدیر آزمون (Exam Administrator)</option>
              <option value="Question Manager">مسئول بانک سوالات (Question Manager)</option>
              <option value="Reviewer">بازبین سوالات (Reviewer)</option>
              <option value="Lab Manager">مدیر لابراتوار (Lab Manager)</option>
              <option value="Proctor">ناظر آزمون (Proctor)</option>
            </Select>
          </Field>
          <Field label="وضعیت دسترسی">
            <Select
              value={formData.status}
              onChange={(e) => setFormData((p) => ({ ...p, status: e.target.value }))}
            >
              <option value="active">فعال</option>
              <option value="inactive">غیرفعال / تعلیق</option>
            </Select>
          </Field>
        </FieldRow>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            {user ? 'ذخیره تغییرات' : 'ایجاد کاربر'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
