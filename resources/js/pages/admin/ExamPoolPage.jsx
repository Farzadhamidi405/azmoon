import { useCallback, useEffect, useState } from 'react';
import { PageHeader, Spinner } from '../../components/ui/index.js';
import { api, API_BASE, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

export default function ExamPoolPage() {
  const [exams, setExams] = useState([]);
  const [selectedExamId, setSelectedExamId] = useState('');
  const [pools, setPools] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  // بارگذاری لیست آزمون‌ها
  useEffect(() => {
    api
      .get(`${API_BASE}/admin/exams`)
      .then((res) => {
        const list = unwrapList(res.data).items || [];
        setExams(list);
        if (list.length > 0) {
          setSelectedExamId(String(list[0].id));
        }
      })
      .catch(() => notifyError('خطا در بارگذاری لیست آزمون‌ها.'))
      .finally(() => setLoading(false));
  }, []);

  // بارگذاری Pool آزمون انتخابی
  const loadPool = useCallback(async (examId) => {
    if (!examId) return;
    setLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/exams/${examId}/pool`);
      const data = res.data?.data || [];
      setPools(data);
    } catch (err) {
      notifyError('خطا در بارگذاری بخش‌ها و Question Pool آزمون.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (selectedExamId) {
      loadPool(selectedExamId);
    }
  }, [selectedExamId, loadPool]);

  const toggleRandomize = (index) => {
    setPools((prev) =>
      prev.map((p, idx) => (idx === index ? { ...p, randomize: !p.randomize } : p))
    );
  };

  const handleSelectedChange = (index, value) => {
    const num = Math.max(1, parseInt(value, 10) || 1);
    setPools((prev) => prev.map((p, idx) => (idx === index ? { ...p, selected: num } : p)));
  };

  const handleSave = async () => {
    if (!selectedExamId || pools.length === 0) return;
    setBusy(true);
    try {
      await api.post(`${API_BASE}/admin/exams/${selectedExamId}/pool`, {
        sections: pools.map((p) => ({ id: p.id, selected: p.selected })),
        randomize_options: pools.some((p) => p.randomize),
      });
      notifySuccess('تنظیمات Question Pool با موفقیت در پایگاه‌داده ذخیره شد.');
    } catch (err) {
      notifyError('خطا در ذخیره تنظیمات Question Pool.');
    } finally {
      setBusy(false);
    }
  };

  const currentExam = exams.find((e) => String(e.id) === String(selectedExamId));

  return (
    <div className="max-w-[800px] mx-auto w-full space-y-4">
      <PageHeader
        title={`Question Pool — ${currentExam?.title || 'آزمون'}`}
        crumb={['آزمون‌ها', 'Question Pool']}
        actions={
          <div className="flex items-center gap-2">
            <select
              value={selectedExamId}
              onChange={(e) => setSelectedExamId(e.target.value)}
              className="rounded border border-line bg-white px-2 py-1 text-xs text-ink font-semibold"
            >
              {exams.map((ex) => (
                <option key={ex.id} value={ex.id}>
                  {ex.title}
                </option>
              ))}
            </select>
            <button
              type="button"
              onClick={handleSave}
              disabled={busy || loading || pools.length === 0}
              className="btn btn-gold text-xs font-semibold cursor-pointer disabled:opacity-50"
            >
              {busy ? <Spinner size="sm" /> : 'ذخیره تنظیمات Pool'}
            </button>
          </div>
        }
      />

      <div className="card space-y-4">
        <div className="helper text-[12.5px] text-ink-soft leading-relaxed">
          به‌جای سوالات ثابت، از هر بخش آزمون یک مجموعهٔ تصادفی از بانک سوالات (Pool) انتخاب می‌شود؛ هر متقاضی سوالات و ترتیب گزینه‌های متفاوتی مشاهده خواهد کرد.
        </div>

        {loading ? (
          <div className="py-8 text-center text-xs text-ink-soft flex flex-col items-center gap-2">
            <Spinner size="md" />
            <span>در حال دریافت اطلاعات Pool آزمون...</span>
          </div>
        ) : pools.length === 0 ? (
          <div className="py-8 text-center text-xs text-ink-soft">
            هیچ بخشی برای این آزمون ثبت نشده است. لطفاً ابتدا بخش‌های آزمون را تعریف کنید.
          </div>
        ) : (
          <table className="w-full text-right mb-4">
            <thead>
              <tr className="border-b border-line text-ink-soft text-xs">
                <th className="py-2.5 px-3">بخش آزمون</th>
                <th className="py-2.5 px-3">مجموع سوالات Pool</th>
                <th className="py-2.5 px-3">تعداد انتخابی</th>
                <th className="py-2.5 px-3">Randomize گزینه‌ها</th>
              </tr>
            </thead>
            <tbody>
              {pools.map((item, idx) => (
                <tr key={item.id || idx} className="border-b border-line-soft hover:bg-row-hover text-xs">
                  <td className="py-3 px-3 font-semibold text-navy">{item.section}</td>
                  <td className="py-3 px-3 font-mono">{item.total}</td>
                  <td className="py-3 px-3">
                    <input
                      type="number"
                      min={1}
                      max={item.total}
                      value={item.selected}
                      onChange={(e) => handleSelectedChange(idx, e.target.value)}
                      className="w-16 rounded border border-line px-2 py-1 text-xs font-mono font-bold text-teal text-center"
                    />
                  </td>
                  <td className="py-3 px-3">
                    <button
                      type="button"
                      onClick={() => toggleRandomize(idx)}
                      className={`chk cursor-pointer ${item.randomize ? 'on' : ''}`}
                      title="تغییر وضعیت تصادفی‌سازی"
                    >
                      {item.randomize ? '✓' : ''}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <div className="section-title text-[14px] font-bold text-navy pt-2">پیش‌نمایش تخصیص متقاضیان</div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="stat">
            <div className="lbl text-xs text-ink-soft">متقاضی شماره ۱</div>
            <div className="num text-[13px] font-mono mt-1 text-navy font-bold">بخش A (۲۰ سوال) · ترتیب تصادفی</div>
          </div>
          <div className="stat">
            <div className="lbl text-xs text-ink-soft">متقاضی شماره ۲</div>
            <div className="num text-[13px] font-mono mt-1 text-navy font-bold">بخش A (۲۰ سوال) · با گزینه‌های Shuffle</div>
          </div>
        </div>
      </div>
    </div>
  );
}

export { ExamPoolPage };
