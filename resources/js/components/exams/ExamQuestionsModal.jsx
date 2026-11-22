import { useEffect, useState, useMemo } from 'react';
import {
  Badge,
  Button,
  DataTable,
  FilterBar,
  FilterInput,
  FilterSelect,
  Modal,
  Spinner,
} from '../ui/index.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

const TYPE_LABELS = {
  single: 'چهارگزینه‌ای',
  multi: 'چندگزینه‌ای',
  true_false: 'صحیح/غلط',
  short_answer: 'پاسخ کوتاه',
};

export default function ExamQuestionsModal({
  open,
  onClose,
  exam,
  onQuestionsUpdated,
  useExamsHook,
}) {
  const [activeTab, setActiveTab] = useState('current'); // 'current' | 'available'
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  // سوالات انتخابی فعلی آزمون
  const [selectedQuestions, setSelectedQuestions] = useState([]);
  
  // سوالات موجود و تاییدشده بانک سوالات
  const [availableQuestions, setAvailableQuestions] = useState([]);
  const [availableLoading, setAvailableLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  // دریافت سوالات فعلی آزمون از API
  const loadExamQuestions = async () => {
    if (!exam?.id) return;
    setLoading(true);
    try {
      const res = await useExamsHook.getQuestions(exam.id);
      setSelectedQuestions(res.data || []);
    } catch (err) {
      notifyError('خطا در بارگذاری سوالات اختصاصی آزمون.');
    } finally {
      setLoading(false);
    }
  };

  // دریافت سوالات مجاز و تاییدشده از بانک سوالات
  const loadAvailableQuestions = async () => {
    if (!exam?.id) return;
    setAvailableLoading(true);
    try {
      const res = await useExamsHook.getAvailableQuestions(exam.id, {
        search: search || undefined,
        question_type: typeFilter || undefined,
        per_page: 50,
      });
      setAvailableQuestions(res.items || []);
    } catch (err) {
      notifyError('خطا در دریافت سوالات تاییدشده بانک سوالات.');
    } finally {
      setAvailableLoading(false);
    }
  };

  useEffect(() => {
    if (open && exam?.id) {
      loadExamQuestions();
      loadAvailableQuestions();
    }
  }, [open, exam?.id]);

  useEffect(() => {
    if (open && exam?.id && activeTab === 'available') {
      loadAvailableQuestions();
    }
  }, [search, typeFilter, activeTab]);

  // مجموع نمرات سوالات انتخاب‌شده
  const totalScore = useMemo(() => {
    return selectedQuestions.reduce((sum, q) => {
      const s = q.pivot?.score !== undefined ? Number(q.pivot.score) : Number(q.score ?? 1);
      return sum + (isNaN(s) ? 1 : s);
    }, 0);
  }, [selectedQuestions]);

  // افزودن سوال به آزمون (جلوگیری از تکراری)
  const handleAddQuestion = (q) => {
    if (selectedQuestions.some((item) => item.id === q.id)) {
      notifyError('این سوال قبلاً به آزمون اضافه شده است.');
      return;
    }
    setSelectedQuestions((prev) => [...prev, { ...q, pivot: { score: q.score ?? 1 } }]);
    notifySuccess(`سوال #${q.id} به لیست آزمون اضافه شد.`);
  };

  // حذف سوال از آزمون
  const handleRemoveQuestion = (qId) => {
    setSelectedQuestions((prev) => prev.filter((item) => item.id !== qId));
  };

  // ذخیره نهایی سوالات در سرور
  const handleSaveSync = async () => {
    if (!exam?.id) return;
    setBusy(true);
    try {
      const qIds = selectedQuestions.map((q) => q.id);
      const scores = {};
      selectedQuestions.forEach((q) => {
        scores[q.id] = q.pivot?.score ?? q.score ?? 1;
      });

      await useExamsHook.syncQuestions(exam.id, qIds, scores);
      notifySuccess(`تعداد ${qIds.length} سوال با موفقیت برای آزمون ذخیره شد.`);
      onQuestionsUpdated?.();
      onClose();
    } catch (err) {
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ذخیره سوالات آزمون.';
      notifyError(msg);
    } finally {
      setBusy(false);
    }
  };

  // ستون‌های جدول سوالات انتخابی آزمون
  const currentColumns = [
    {
      key: 'question_text',
      header: 'متن سوال',
      primary: true,
      render: (row) => (
        <div className="max-w-[360px]">
          <div className="font-semibold text-ink line-clamp-2">{row.question_text}</div>
          <div className="text-[11px] text-ink-soft mt-0.5">
            {row.section_name && `${row.section_name} · `}
            {TYPE_LABELS[row.question_type] || row.question_type}
          </div>
        </div>
      ),
    },
    {
      key: 'score',
      header: 'نمره',
      render: (row) => (
        <span className="font-mono font-bold text-navy">
          {row.pivot?.score ?? row.score ?? 1}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'end',
      actions: true,
      render: (row) => (
        <Button
          size="sm"
          variant="outline"
          className="text-red hover:bg-red/10 border-red/30"
          onClick={() => handleRemoveQuestion(row.id)}
        >
          حذف از آزمون
        </Button>
      ),
    },
  ];

  // ستون‌های جدول سوالات در دسترس بانک سوالات
  const availableColumns = [
    {
      key: 'question_text',
      header: 'متن سوال',
      primary: true,
      render: (row) => (
        <div className="max-w-[360px]">
          <div className="font-semibold text-ink line-clamp-2">{row.question_text}</div>
          <div className="text-[11px] text-ink-soft mt-0.5">
            {row.section_name && `${row.section_name} · `}
            {TYPE_LABELS[row.question_type] || row.question_type}
            {row.topic && ` · ${row.topic}`}
          </div>
        </div>
      ),
    },
    {
      key: 'score',
      header: 'نمره',
      render: (row) => <span className="font-mono font-bold text-navy">{row.score ?? 1}</span>,
    },
    {
      key: 'status',
      header: 'وضعیت',
      render: (row) => <Badge tone="teal">تأیید شده</Badge>,
    },
    {
      key: 'actions',
      header: '',
      align: 'end',
      actions: true,
      render: (row) => {
        const isSelected = selectedQuestions.some((q) => q.id === row.id);
        return isSelected ? (
          <span className="text-xs text-teal font-bold px-2 py-1 bg-teal/10 rounded">
            انتخاب‌شده ✓
          </span>
        ) : (
          <Button
            size="sm"
            variant="gold"
            onClick={() => handleAddQuestion(row)}
          >
            + افزودن به آزمون
          </Button>
        );
      },
    },
  ];

  if (!exam) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`مدیریت سوالات آزمون: ${exam.title}`}
      width="max-w-4xl"
    >
      <div className="space-y-4 text-xs">
        {/* نوار آمار و اطلاعات آزمون */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-xl bg-paper/60 border border-line">
          <div>
            <div className="text-ink-soft">مضمون آزمون:</div>
            <div className="font-bold text-navy mt-0.5">{exam.subject?.name || '—'}</div>
          </div>
          <div>
            <div className="text-ink-soft">تعداد سوالات انتخاب‌شده:</div>
            <div className="font-bold font-mono text-base text-navy mt-0.5">
              {selectedQuestions.length} سوال
            </div>
          </div>
          <div>
            <div className="text-ink-soft">مجموع نمرات فعلی:</div>
            <div className="font-bold font-mono text-base text-teal mt-0.5">
              {Math.round(totalScore * 100) / 100} نمره
            </div>
          </div>
          <div>
            <div className="text-ink-soft">نمره کل آزمون:</div>
            <div className="font-bold font-mono text-base text-navy mt-0.5">
              {exam.total_marks} نمره
            </div>
          </div>
        </div>

        {/* دکمه‌های جابجایی تب */}
        <div className="flex items-center gap-2 border-b border-line pb-2">
          <button
            type="button"
            onClick={() => setActiveTab('current')}
            className={`btn text-xs font-semibold cursor-pointer ${
              activeTab === 'current' ? 'btn-teal' : 'btn-outline'
            }`}
          >
            سوالات انتخاب‌شده این آزمون ({selectedQuestions.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('available')}
            className={`btn text-xs font-semibold cursor-pointer ${
              activeTab === 'available' ? 'btn-teal' : 'btn-outline'
            }`}
          >
            + انتخاب از بانک سوالات تاییدشده
          </button>
        </div>

        {activeTab === 'current' ? (
          <div>
            {loading ? (
              <div className="py-8 text-center text-ink-soft flex items-center justify-center gap-2">
                <Spinner size="sm" />
                <span>در حال بارگذاری سوالات آزمون...</span>
              </div>
            ) : (
              <DataTable
                columns={currentColumns}
                rows={selectedQuestions}
                data={selectedQuestions}
                emptyTitle="هنوز هیچ سوالی برای این آزمون انتخاب نشده است. از تب «انتخاب از بانک سوالات» سوالات موردنظر را اضافه نمایید."
              />
            )}
          </div>
        ) : (
          <div className="space-y-3">
            <FilterBar>
              <FilterInput
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="جستجو در سوالات تاییدشده..."
                className="max-w-xs"
              />
              <FilterSelect
                value={typeFilter}
                onChange={(e) => setTypeFilter(e.target.value)}
              >
                <option value="">نوع: همه</option>
                <option value="single">چهارگزینه‌ای</option>
                <option value="multi">چندگزینه‌ای</option>
                <option value="true_false">صحیح/غلط</option>
                <option value="short_answer">پاسخ کوتاه</option>
              </FilterSelect>
            </FilterBar>

            {availableLoading ? (
              <div className="py-8 text-center text-ink-soft flex items-center justify-center gap-2">
                <Spinner size="sm" />
                <span>در حال بارگذاری سوالات تاییدشده...</span>
              </div>
            ) : (
              <DataTable
                columns={availableColumns}
                rows={availableQuestions}
                data={availableQuestions}
                emptyTitle="هیچ سوال تأییدشده‌ای برای این مضمون یافت نشد. لطفاً ابتدا سوالات را در بانک سوالات ایجاد و تأیید کنید."
              />
            )}
          </div>
        )}

        {/* فوتر اکشن‌ها */}
        <div className="flex items-center justify-between pt-3 border-t border-line">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            انصراف
          </Button>

          <Button
            variant="gold"
            onClick={handleSaveSync}
            loading={busy}
          >
            ذخیره سوالات آزمون در پایگاه‌داده
          </Button>
        </div>
      </div>
    </Modal>
  );
}
