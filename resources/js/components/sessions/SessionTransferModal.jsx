import { useState } from 'react';
import { Button, Field, Input, Modal, Select } from '../ui/index.js';
import useComputers from '../../hooks/useComputers.js';

export default function SessionTransferModal({ open, onClose, onTransfer, attempt, busy = false }) {
  const { items: computers } = useComputers();
  const [formData, setFormData] = useState({
    target_computer_id: '',
    reason: '',
  });
  const [errors, setErrors] = useState({});

  const availableComputers = computers.filter(
    (c) => c.id !== attempt?.computer_id && (c.status === 'online' || c.status === 'idle')
  );

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onTransfer(attempt.id, formData);
      onClose();
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`انتقال نشست متقاضی ${attempt?.candidate?.name || ''}`}
      size="md"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="bg-paper p-3 rounded-lg text-xs space-y-1">
          <div>
            متقاضی: <b className="text-navy">{attempt?.candidate?.name}</b> (کد: {attempt?.candidate?.candidate_code})
          </div>
          <div>
            سیستم مبدأ: <b className="text-navy">{attempt?.computer?.computer_code || 'سیستم فعلی'}</b>
          </div>
          <div>
            آزمون: <b>{attempt?.exam?.title}</b>
          </div>
        </div>

        <Field label="کامپیوتر مقصد (سیستم آماده/آنلاین)" required error={errors.target_computer_id}>
          <Select
            value={formData.target_computer_id}
            onChange={(e) => setFormData((p) => ({ ...p, target_computer_id: e.target.value }))}
          >
            <option value="">انتخاب کامپیوتر مقصد...</option>
            {availableComputers.map((c) => (
              <option key={c.id} value={c.id}>
                {c.computer_code} — {c.ip_address} ({c.lab?.name || 'لابراتوار'})
              </option>
            ))}
          </Select>
        </Field>

        <Field label="دلیل انتقال (گزارش اشکال فنی، خرابی سیستم و ...)" required error={errors.reason}>
          <Input
            value={formData.reason}
            onChange={(e) => setFormData((p) => ({ ...p, reason: e.target.value }))}
            placeholder="مثلاً: قطع اتصال صفحه کلید یا خاموش شدن ناگهانی سیستم"
          />
        </Field>

        <div className="text-xs text-ink-soft leading-relaxed">
          پس از انتقال، تایمر و وضعیت پاسخ‌ها از آخرین وضعیت همگام‌سازی‌شده (Heartbeat) روی سیستم مقصد بارگذاری خواهند شد و سیستم مبدأ آزاد می‌شود.
        </div>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            تأیید انتقال نشست
          </Button>
        </div>
      </form>
    </Modal>
  );
}
