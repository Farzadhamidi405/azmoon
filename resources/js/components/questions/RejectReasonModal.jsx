import { useEffect, useState } from 'react';
import { Button, Modal, Textarea } from '../ui/index.js';

export default function RejectReasonModal({
  open,
  onClose,
  onConfirm,
  question,
  busy = false,
}) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (open) {
      setReason('');
      setError('');
    }
  }, [open, question?.id]);

  if (!question) return null;

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!reason.trim()) {
      setError('لطفاً دلیل رد سوال را وارد نمایید (این دلیل برای طراح سوال نمایش داده خواهد شد).');
      return;
    }
    if (reason.trim().length < 3) {
      setError('دلیل رد باید حداقل شامل ۳ حرف باشد.');
      return;
    }
    setError('');
    onConfirm(question, reason.trim());
  };

  const handleClose = () => {
    if (busy) return;
    setReason('');
    setError('');
    onClose();
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title="رد سوال و ثبت بازخورد به طراح"
      width="max-w-md"
    >
      <form onSubmit={handleSubmit} className="space-y-3.5 text-xs">
        <div className="p-3 rounded-xl bg-red/10 border border-red/30 text-red leading-relaxed">
          سوال شماره <strong className="font-mono">#{question.id}</strong> رد شده و به وضعیت «پیش‌نویس (Draft)» بازمی‌گردد تا طراح سوال بتواند بر اساس بازخورد شما آن را اصلاح و مجدداً برای بررسی ارسال کند.
        </div>

        <div className="space-y-1">
          <label className="font-bold text-navy block">
            دلیل رد سوال <span className="text-red">*</span>
          </label>
          <Textarea
            rows={4}
            value={reason}
            onChange={(e) => {
              setReason(e.target.value);
              if (error) setError('');
            }}
            placeholder="مثلاً: گزینه B با متن سوال همخوانی ندارد یا کلید پاسخ اشتباه انتخاب شده است..."
            className="w-full text-xs"
            disabled={busy}
          />
          {error && <p className="text-red text-[11px] font-medium">{error}</p>}
        </div>

        <div className="flex items-center justify-end gap-2 pt-2 border-t border-line">
          <Button
            type="button"
            variant="ghost"
            onClick={handleClose}
            disabled={busy}
          >
            انصراف
          </Button>
          <Button
            type="submit"
            variant="outline"
            className="text-red hover:bg-red/10 border-red/40"
            loading={busy}
          >
            ثبت رد سوال
          </Button>
        </div>
      </form>
    </Modal>
  );
}
