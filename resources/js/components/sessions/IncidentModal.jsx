import { useState } from 'react';
import { Button, Field, Input, Modal, Select, Textarea } from '../ui/index.js';

export default function IncidentModal({ open, onClose, onReport, attempt, busy = false }) {
  const [formData, setFormData] = useState({
    title: '',
    incident_type: 'technical_glitch',
    severity: 'medium',
    description: '',
  });
  const [errors, setErrors] = useState({});

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onReport({
        ...formData,
        attempt_id: attempt?.id,
        candidate_id: attempt?.candidate?.id || attempt?.candidate_id,
        computer_id: attempt?.computer?.id || attempt?.computer_id,
      });
      onClose();
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="ثبت گزارش رویداد یا تخلف (Incident)" size="md">
      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="عنوان رویداد" required error={errors.title}>
          <Input
            value={formData.title}
            onChange={(e) => setFormData((p) => ({ ...p, title: e.target.value }))}
            placeholder="مثلاً: تلاش برای خروج از مرورگر آزمون"
          />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="نوع رویداد">
            <Select
              value={formData.incident_type}
              onChange={(e) => setFormData((p) => ({ ...p, incident_type: e.target.value }))}
            >
              <option value="technical_glitch">اشکال فنی (Technical)</option>
              <option value="anti_cheat">هشدار تقلب (Anti-Cheat)</option>
              <option value="cheating_attempt">تخلف محرز متقاضی</option>
              <option value="disruption">بی‌نظمی در سالن</option>
              <option value="other">سایر موارد</option>
            </Select>
          </Field>

          <Field label="سطح فوریت">
            <Select
              value={formData.severity}
              onChange={(e) => setFormData((p) => ({ ...p, severity: e.target.value }))}
            >
              <option value="low">کم (Low)</option>
              <option value="medium">متوسط (Medium)</option>
              <option value="high">فوری / بالا (High)</option>
              <option value="critical">بحرانی (Critical)</option>
            </Select>
          </Field>
        </div>

        <Field label="شرح کامل حادثه" required error={errors.description}>
          <Textarea
            rows={3}
            value={formData.description}
            onChange={(e) => setFormData((p) => ({ ...p, description: e.target.value }))}
            placeholder="شرح دقیق وضعیت، اقدامات انجام شده توسط ناظر..."
          />
        </Field>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            ثبت رویداد در لاگ
          </Button>
        </div>
      </form>
    </Modal>
  );
}
