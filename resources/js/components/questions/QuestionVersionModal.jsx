import { useEffect, useState } from 'react';
import { Button, Modal, Spinner } from '../ui/index.js';
import formatDate from '../../lib/formatDate.js';

export default function QuestionVersionModal({ open, onClose, question, onGetVersions }) {
  const [versions, setVersions] = useState([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (open && question?.id) {
      setLoading(true);
      onGetVersions(question.id)
        .then((res) => setVersions(res || []))
        .catch(() => setVersions([]))
        .finally(() => setLoading(false));
    }
  }, [open, question?.id]);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`تاریخچه نسخه‌های سوال #${question?.id || ''}`}
      size="md"
    >
      <div className="space-y-3">
        {loading ? (
          <div className="py-8 text-center text-ink-soft flex items-center justify-center gap-2">
            <Spinner size="sm" />
            <span>در حال بارگذاری تاریخچه نسخه‌ها...</span>
          </div>
        ) : versions.length === 0 ? (
          <div className="text-center py-6 text-sm text-ink-soft">
            هیچ نسخه پیشینی برای این سوال ثبت نشده است (نسخه جاری تنها نسخه است).
          </div>
        ) : (
          versions.map((ver, idx) => (
            <div
              key={ver.id || idx}
              className="p-3 border border-line rounded-lg bg-paper/40 space-y-1 text-sm"
            >
              <div className="flex items-center justify-between text-xs text-ink-soft">
                <span className="font-bold text-navy">نسخه {ver.version_number || idx + 1}</span>
                <span>{ver.created_at ? formatDate(ver.created_at) : '—'}</span>
              </div>
              <div className="font-semibold text-ink">{ver.question_text || question?.question_text}</div>
              {ver.changed_by && (
                <div className="text-xs text-ink-soft">ویرایش‌شده توسط: {ver.changed_by.name || 'کاربر سیستم'}</div>
              )}
            </div>
          ))
        )}

        <div className="pt-2 border-t border-line text-xs text-ink-soft leading-relaxed">
          امتحان‌های منتشرشده قبلی، محتوای سوال را از Snapshot منجمد خود می‌خوانند و از تغییرات نسخه‌های جدید متأثر نمی‌شوند.
        </div>

        <div className="flex justify-end pt-2">
          <Button variant="outline" onClick={onClose}>
            بستن
          </Button>
        </div>
      </div>
    </Modal>
  );
}
