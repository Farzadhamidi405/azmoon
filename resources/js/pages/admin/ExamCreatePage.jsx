import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Badge,
  Button,
  DataTable,
  Field,
  FieldRow,
  FilterBar,
  FilterInput,
  FilterSelect,
  Input,
  Modal,
  PageHeader,
  Select,
  Spinner,
  Textarea,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import useSubjects from '../../hooks/useSubjects.js';
import api, { API_BASE, unwrapList } from '../../lib/api.js';
import { notifyError, notifySuccess, notifyWarning } from '../../lib/notify.js';

const TYPE_LABELS = {
  single: 'چهارگزینه‌ای',
  multi: 'چندگزینه‌ای',
  true_false: 'صحیح/غلط',
  short_answer: 'پاسخ کوتاه',
};

const DIFFICULTY_LABELS = {
  easy: 'آسان',
  medium: 'متوسط',
  hard: 'سخت',
};

export default function ExamCreatePage() {
  const { id } = useParams();
  const isEdit = Boolean(id);
  const navigate = useNavigate();

  const { create, update, changeStatus, getExam } = useExams();
  const { items: subjects, loading: subjectsLoading } = useSubjects();

  const [loadingExam, setLoadingExam] = useState(isEdit);
  const [examNotFound, setExamNotFound] = useState(false);
  const [examStatus, setExamStatus] = useState('draft');

  const [submitting, setSubmitting] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [errors, setErrors] = useState({});

  // فرم آزمون مطابق Prototype v3
  const [formData, setFormData] = useState({
    title: 'English Language Examination',
    description: 'امتحان زبان انگلیسی برای متقاضیان بست‌های کادری',
    subject_id: '',
    duration_minutes: 60,
    total_marks: 100,
    pass_marks: 60,
    start_date: '۱۴۰۴/۰۶/۱۲ — ۰۸:۰۰',
    end_date: '۱۴۰۴/۰۶/۱۲ — ۱۶:۰۰',
    allow_back_navigation: true,
    randomize_questions: true,
    randomize_options: true,
    show_result_immediately: false,
    sections: [
      { name: 'Grammar', count: 20, color: 'var(--gold)' },
      { name: 'Vocabulary', count: 10, color: 'var(--teal)' },
      { name: 'Reading', count: 10, color: 'var(--navy-3)' },
      { name: 'Listening', count: 10, color: 'var(--amber)' },
    ],
  });

  // سوالات متصل‌شده به آزمون
  const [selectedQuestions, setSelectedQuestions] = useState([]);

  // مودال انتخاب سوال از مخزن فعال (Active in Pool)
  const [questionModalOpen, setQuestionModalOpen] = useState(false);
  const [availableQuestions, setAvailableQuestions] = useState([]);
  const [availableLoading, setAvailableLoading] = useState(false);
  const [qSearch, setQSearch] = useState('');
  const [qType, setQType] = useState('');
  const [qDifficulty, setQDifficulty] = useState('');

  // آیا آزمون در وضعیتی است که ویرایش مجاز باشد؟
  const isEditable = !isEdit || ['draft', 'under_review'].includes(examStatus);

  // بارگذاری اطلاعات آزمون در حالت ویرایش
  useEffect(() => {
    if (!isEdit) return;

    let mounted = true;
    setLoadingExam(true);
    setExamNotFound(false);

    getExam(id)
      .then((exam) => {
        if (!mounted) return;
        if (!exam || !exam.id) {
          setExamNotFound(true);
          notifyError('آزمون مورد نظر یافت نشد.');
          return;
        }

        setExamStatus(exam.status || 'draft');

        // استخراج بخش‌ها
        let sections = [];
        if (exam.sections && exam.sections.length > 0) {
          sections = exam.sections.map((s, idx) => ({
            name: s.name,
            count: s.question_count || 10,
            color: ['var(--gold)', 'var(--teal)', 'var(--navy-3)', 'var(--amber)'][idx % 4],
          }));
        } else {
          sections = [
            { name: 'Grammar', count: 20, color: 'var(--gold)' },
            { name: 'Vocabulary', count: 10, color: 'var(--teal)' },
            { name: 'Reading', count: 10, color: 'var(--navy-3)' },
            { name: 'Listening', count: 10, color: 'var(--amber)' },
          ];
        }

        setFormData({
          title: exam.title || '',
          description: exam.description || '',
          subject_id: exam.subject_id ? String(exam.subject_id) : '',
          duration_minutes: exam.duration_minutes ?? 60,
          total_marks: exam.total_marks ? Number(exam.total_marks) : 100,
          pass_marks: exam.pass_marks ? Number(exam.pass_marks) : 60,
          start_date: '۱۴۰۴/۰۶/۱۲ — ۰۸:۰۰',
          end_date: '۱۴۰۴/۰۶/۱۲ — ۱۶:۰۰',
          allow_back_navigation: Boolean(exam.allow_back_navigation),
          randomize_questions: Boolean(exam.randomize_questions),
          randomize_options: Boolean(exam.randomize_options),
          show_result_immediately: Boolean(exam.show_result_immediately),
          sections,
        });

        // استخراج سوالات متصل‌شده
        if (exam.questions && Array.isArray(exam.questions)) {
          setSelectedQuestions(exam.questions);
        }
      })
      .catch((err) => {
        if (!mounted) return;
        setExamNotFound(true);
        const msg = err.response?.data?.message || 'خطا در بارگذاری اطلاعات آزمون.';
        notifyError(msg);
      })
      .finally(() => {
        if (mounted) setLoadingExam(false);
      });

    return () => {
      mounted = false;
    };
  }, [id, isEdit]);

  const handleChange = (field, val) => {
    setFormData((p) => ({ ...p, [field]: val }));
    setErrors((p) => ({ ...p, [field]: null }));
  };

  const addSection = () => {
    const name = prompt('نام بخش جدید را وارد نمایید:');
    if (!name) return;
    const count = Number(prompt('تعداد سوالات این بخش:', '10')) || 10;
    setFormData((p) => ({
      ...p,
      sections: [...p.sections, { name, count, color: 'var(--navy-2)' }],
    }));
  };

  const removeSection = (idx) => {
    if (formData.sections.length <= 1) {
      notifyWarning('حداقل یک بخش برای آزمون الزامی است.');
      return;
    }
    setFormData((p) => ({
      ...p,
      sections: p.sections.filter((_, i) => i !== idx),
    }));
  };

  // بارگذاری سوالات فعال بانک سوالات برای مضمون جاری
  const loadAvailableQuestions = async () => {
    const activeSubjectId = formData.subject_id || subjects[0]?.id;
    if (!activeSubjectId) return;

    setAvailableLoading(true);
    try {
      const res = await api.get(`${API_BASE}/admin/questions`, {
        params: {
          subject_id: activeSubjectId,
          status: 'active', // فقط سوالات Active in Pool
          search: qSearch || undefined,
          question_type: qType || undefined,
          difficulty: qDifficulty || undefined,
          per_page: 50,
        },
      });
      const data = unwrapList(res.data);
      setAvailableQuestions(data.items || []);
    } catch (err) {
      notifyError('خطا در بارگذاری سوالات مخزن فعال.');
    } finally {
      setAvailableLoading(false);
    }
  };

  useEffect(() => {
    if (questionModalOpen) {
      loadAvailableQuestions();
    }
  }, [questionModalOpen, formData.subject_id, qSearch, qType, qDifficulty]);

  const handleAddQuestion = (q) => {
    if (selectedQuestions.some((item) => item.id === q.id)) {
      notifyWarning('این سوال قبلاً به آزمون اضافه شده است.');
      return;
    }
    setSelectedQuestions((prev) => [...prev, q]);
    notifySuccess(`سوال #${q.id} به آزمون اضافه شد.`);
  };

  const handleRemoveQuestion = (qId) => {
    setSelectedQuestions((prev) => prev.filter((item) => item.id !== qId));
  };

  const buildPayload = () => {
    if (!subjects.length) {
      return null;
    }

    const resolvedSubjectId = formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '');
    if (!resolvedSubjectId) {
      return null;
    }

    const totalMarks = Number(formData.total_marks) || 100;
    const totalQuestions =
      formData.sections.reduce((acc, s) => acc + (Number(s.count) || 0), 0) || 1;

    const sectionsPayload = formData.sections.map((s, idx) => {
      const qCount = Number(s.count) || 10;
      const marks = Math.round(((qCount / totalQuestions) * totalMarks) * 100) / 100;
      return {
        name: s.name.trim(),
        question_count: qCount,
        total_marks: marks,
        display_order: idx + 1,
      };
    });

    // رفع خطای احتمالی اعشار در مجموع نمرات بخش‌ها
    const currentSum = sectionsPayload.reduce((acc, s) => acc + s.total_marks, 0);
    if (sectionsPayload.length > 0 && Math.abs(currentSum - totalMarks) > 0.01) {
      const diff = Math.round((totalMarks - currentSum) * 100) / 100;
      sectionsPayload[sectionsPayload.length - 1].total_marks =
        Math.round((sectionsPayload[sectionsPayload.length - 1].total_marks + diff) * 100) / 100;
    }

    const questionIds = selectedQuestions.map((q) => q.id);

    return {
      title: formData.title.trim(),
      description: formData.description?.trim() || null,
      subject_id: Number(resolvedSubjectId),
      duration_minutes: Number(formData.duration_minutes),
      total_marks: totalMarks,
      pass_marks: Number(formData.pass_marks),
      allow_back_navigation: Boolean(formData.allow_back_navigation),
      randomize_questions: Boolean(formData.randomize_questions),
      randomize_options: Boolean(formData.randomize_options),
      show_result_immediately: Boolean(formData.show_result_immediately),
      sections: sectionsPayload,
      question_ids: questionIds,
    };
  };

  const handleSaveDraft = async () => {
    if (submitting || publishing) return;

    if (!subjects.length) {
      notifyError('هیچ مضمونی در سامانه تعریف نشده است. لطفاً ابتدا از بخش مضامین، مضمون مربوطه را ایجاد کنید.');
      return;
    }

    const payload = buildPayload();
    if (!payload || !payload.subject_id) {
      setErrors({ subject_id: 'لطفاً مضمون آزمون را انتخاب نمایید.' });
      return;
    }

    setSubmitting(true);
    setErrors({});
    try {
      if (isEdit) {
        await update(id, payload);
        notifySuccess('تغییرات آزمون با موفقیت در دیتابیس ذخیره شد.');
      } else {
        await create(payload);
        notifySuccess('آزمون به‌صورت پیش‌نویس (Draft) با موفقیت در دیتابیس ذخیره شد.');
      }
      navigate('/admin/exams');
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
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ذخیره آزمون.';
      notifyError(msg);
    } finally {
      setSubmitting(false);
    }
  };

  const handleSubmitForReview = async () => {
    if (submitting || publishing) return;

    if (!subjects.length) {
      notifyError('هیچ مضمونی در سامانه تعریف نشده است. لطفاً ابتدا از بخش مضامین، مضمون مربوطه را ایجاد کنید.');
      return;
    }

    const payload = buildPayload();
    if (!payload || !payload.subject_id) {
      setErrors({ subject_id: 'لطفاً مضمون آزمون را انتخاب نمایید.' });
      return;
    }

    setPublishing(true);
    setErrors({});
    try {
      let savedExam = null;
      if (isEdit) {
        savedExam = await update(id, payload);
      } else {
        savedExam = await create(payload);
      }

      const targetId = savedExam?.id || id;
      if (targetId && examStatus === 'draft') {
        try {
          await changeStatus(targetId, 'under_review');
          notifySuccess('آزمون ذخیره شد و با موفقیت برای بررسی (Under Review) ارسال گردید.');
        } catch {
          notifyWarning('آزمون ذخیره شد، اما ارسال آن برای بررسی با خطا مواجه گردید.');
        }
      } else {
        notifySuccess('آزمون با موفقیت ذخیره شد.');
      }
      navigate('/admin/exams');
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
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ثبت آزمون.';
      notifyError(msg);
    } finally {
      setPublishing(false);
    }
  };

  // مجموع نمرات سوالات انتخاب‌شده
  const calculatedTotalScore = useMemo(() => {
    return selectedQuestions.reduce((sum, q) => sum + Number(q.pivot?.score ?? q.score ?? 1), 0);
  }, [selectedQuestions]);

  if (loadingExam) {
    return (
      <div className="flex flex-col items-center justify-center p-16 gap-3">
        <Spinner size="lg" />
        <div className="text-xs text-ink-soft">در حال بارگذاری اطلاعات آزمون از سرور...</div>
      </div>
    );
  }

  if (examNotFound) {
    return (
      <div className="card max-w-md mx-auto text-center p-8 space-y-4 my-8">
        <div className="text-3xl">⚠️</div>
        <div className="font-bold text-navy text-base">آزمون مورد نظر یافت نشد</div>
        <p className="text-xs text-ink-soft leading-relaxed">
          شناسه آزمون نامعتبر است یا این آزمون قبلاً حذف شده است.
        </p>
        <Button variant="primary" onClick={() => navigate('/admin/exams')}>
          بازگشت به فهرست آزمون‌ها
        </Button>
      </div>
    );
  }

  return (
    <>
      <PageHeader
        title={isEdit ? `ویرایش آزمون: ${formData.title}` : 'ایجاد آزمون جدید'}
        crumb={['آزمون‌ها', isEdit ? 'ویرایش آزمون' : 'ایجاد جدید']}
        actions={
          <Button variant="outline" onClick={() => navigate('/admin/exams')}>
            بازگشت به لیست
          </Button>
        }
      />

      <div className="max-w-[760px] space-y-4">
        {/* هشدار وضعیت غیرقابل ویرایش در صورت وجود */}
        {!isEditable && (
          <div className="p-3.5 rounded-xl border border-amber/40 bg-amber/10 text-xs text-navy font-bold leading-relaxed">
            ⚠️ این آزمون در وضعیت «{examStatus}» قرار دارد. به منظور حفظ یکپارچگی سوابق داوطلبان و آزمون‌های منتشرشده، ویرایش فیلدهای اصلی و سوالات مسدود است.
          </div>
        )}

        {!subjectsLoading && subjects.length === 0 && (
          <div className="p-3.5 rounded-xl border border-gold/40 bg-gold/10 text-xs text-navy font-bold leading-relaxed">
            ⚠️ توجه: هیچ مضمونی در سامانه یافت نشد. برای ایجاد آزمون، لازم است ابتدا از منوی مضامین حداقل یک مضمون ثبت شود.
          </div>
        )}

        <div className="card space-y-4">
          <Field label="عنوان آزمون" required error={errors.title}>
            <Input
              value={formData.title}
              disabled={!isEditable}
              onChange={(e) => handleChange('title', e.target.value)}
              placeholder="مثلاً: English Language Examination"
            />
          </Field>

          <Field label="مضمون مربوطه" required error={errors.subject_id}>
            <Select
              value={formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '')}
              onChange={(e) => handleChange('subject_id', e.target.value)}
              disabled={!isEditable || subjects.length === 0}
            >
              <option value="">
                {subjects.length === 0 ? 'هیچ مضمونی موجود نیست' : 'انتخاب مضمون...'}
              </option>
              {subjects.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.code})
                </option>
              ))}
            </Select>
          </Field>

          <Field label="توضیحات" error={errors.description}>
            <Textarea
              rows={2}
              value={formData.description}
              disabled={!isEditable}
              onChange={(e) => handleChange('description', e.target.value)}
              placeholder="توضیحات آزمون برای اساتید و داوطلبان..."
            />
          </Field>

          <FieldRow cols={3}>
            <Field label="مدت زمان (دقیقه)" required error={errors.duration_minutes}>
              <Input
                type="number"
                min="1"
                max="480"
                value={formData.duration_minutes}
                disabled={!isEditable}
                onChange={(e) => handleChange('duration_minutes', Number(e.target.value))}
              />
            </Field>

            <Field label="نمره کل" required error={errors.total_marks}>
              <Input
                type="number"
                min="1"
                value={formData.total_marks}
                disabled={!isEditable}
                onChange={(e) => handleChange('total_marks', Number(e.target.value))}
              />
            </Field>

            <Field label="نمره قبولی" required error={errors.pass_marks}>
              <Input
                type="number"
                min="1"
                value={formData.pass_marks}
                disabled={!isEditable}
                onChange={(e) => handleChange('pass_marks', Number(e.target.value))}
              />
            </Field>
          </FieldRow>

          <FieldRow cols={2}>
            <Field label="تاریخ شروع (جهت نمایش در کارت و تقویم)">
              <Input
                value={formData.start_date}
                disabled={!isEditable}
                onChange={(e) => handleChange('start_date', e.target.value)}
                placeholder="۱۴۰۴/۰۶/۱۲ — ۰۸:۰۰"
              />
            </Field>

            <Field label="تاریخ پایان (جهت نمایش در کارت و تقویم)">
              <Input
                value={formData.end_date}
                disabled={!isEditable}
                onChange={(e) => handleChange('end_date', e.target.value)}
                placeholder="۱۴۰۴/۰۶/۱۲ — ۱۶:۰۰"
              />
            </Field>
          </FieldRow>

          {/* بخش‌های آزمون */}
          <div className="section-title flex items-center justify-between">
            <span>بخش‌های آزمون</span>
            <span className="text-xs text-ink-soft font-normal">
              مجموع سوالات بخش‌ها:{' '}
              <b>
                {formData.sections.reduce((acc, s) => acc + (Number(s.count) || 0), 0)}
              </b>
            </span>
          </div>

          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-2.5">
            {formData.sections.map((sec, idx) => (
              <div
                key={idx}
                className="stat p-3 rounded-xl border border-line bg-panel relative overflow-hidden"
                style={{ '--stat-c': sec.color }}
              >
                <div className="flex items-center justify-between">
                  <div className="text-xs text-ink-soft">{sec.name}</div>
                  {isEditable && formData.sections.length > 1 && (
                    <button
                      type="button"
                      onClick={() => removeSection(idx)}
                      className="text-red/60 hover:text-red text-xs px-1"
                      title="حذف این بخش"
                    >
                      ×
                    </button>
                  )}
                </div>
                <div className="text-base font-bold text-navy mt-1">{sec.count} سوال</div>
              </div>
            ))}
          </div>

          {isEditable && (
            <Button type="button" variant="outline" size="sm" onClick={addSection}>
              + افزودن بخش جدید
            </Button>
          )}

          {/* تنظیمات پیشرفته */}
          <div className="section-title">تنظیمات پیشرفته</div>
          <FieldRow cols={3}>
            <Field label="حرکت به سوال قبلی">
              <Select
                value={formData.allow_back_navigation ? 'yes' : 'no'}
                disabled={!isEditable}
                onChange={(e) => handleChange('allow_back_navigation', e.target.value === 'yes')}
              >
                <option value="yes">مجاز</option>
                <option value="no">غیرمجاز</option>
              </Select>
            </Field>

            <Field label="ترتیب تصادفی سوالات">
              <Select
                value={formData.randomize_questions ? 'yes' : 'no'}
                disabled={!isEditable}
                onChange={(e) => handleChange('randomize_questions', e.target.value === 'yes')}
              >
                <option value="yes">فعال</option>
                <option value="no">غیرفعال</option>
              </Select>
            </Field>

            <Field label="نمایش نتیجه بعد از اتمام">
              <Select
                value={formData.show_result_immediately ? 'yes' : 'no'}
                disabled={!isEditable}
                onChange={(e) => handleChange('show_result_immediately', e.target.value === 'yes')}
              >
                <option value="no">خیر</option>
                <option value="yes">بلی</option>
              </Select>
            </Field>
          </FieldRow>

          {/* سوالات متصل‌شده به آزمون از مخزن فعال */}
          <div className="border-t border-line pt-4 space-y-3">
            <div className="flex items-center justify-between">
              <div>
                <div className="font-bold text-navy text-sm">
                  سوالات متصل به آزمون (Active in Pool)
                </div>
                <div className="text-xs text-ink-soft mt-0.5">
                  تعداد انتخاب‌شده: <b>{selectedQuestions.length}</b> سوال | مجموع نمرات:{' '}
                  <b>{calculatedTotalScore}</b> نمره
                </div>
              </div>
              {isEditable && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setQuestionModalOpen(true)}
                >
                  + انتخاب سوالات از مخزن فعال
                </Button>
              )}
            </div>

            {selectedQuestions.length === 0 ? (
              <div className="p-4 rounded-xl border border-dashed border-line text-center text-xs text-ink-soft">
                هنوز سوالی از مخزن فعال به این آزمون متصل نشده است.
              </div>
            ) : (
              <div className="border border-line rounded-xl overflow-hidden max-h-60 overflow-y-auto">
                <table className="w-full text-xs">
                  <thead className="bg-panel-2 border-b border-line text-ink-soft font-semibold">
                    <tr>
                      <th className="p-2 text-right">#</th>
                      <th className="p-2 text-right">متن سوال</th>
                      <th className="p-2 text-right">بخش</th>
                      <th className="p-2 text-right">نمره</th>
                      {isEditable && <th className="p-2 text-center w-12">حذف</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {selectedQuestions.map((q, idx) => (
                      <tr key={q.id} className="hover:bg-panel-2/50">
                        <td className="p-2 font-mono text-ink-soft">{idx + 1}</td>
                        <td className="p-2 font-medium text-navy truncate max-w-[280px]">
                          {q.question_text}
                        </td>
                        <td className="p-2 text-ink-soft">{q.section_name || 'عمومی'}</td>
                        <td className="p-2 font-mono font-bold text-navy">
                          {q.pivot?.score ?? q.score ?? 1}
                        </td>
                        {isEditable && (
                          <td className="p-2 text-center">
                            <button
                              type="button"
                              onClick={() => handleRemoveQuestion(q.id)}
                              className="text-red/70 hover:text-red font-bold px-1"
                              title="حذف از آزمون"
                            >
                              ✕
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* دکمه‌های فرم */}
          <div className="flex items-center gap-2.5 pt-4 border-t border-line">
            <Button
              type="button"
              variant="outline"
              loading={submitting}
              disabled={publishing || subjects.length === 0 || !isEditable}
              onClick={handleSaveDraft}
            >
              {isEdit ? 'ذخیره تغییرات' : 'ذخیره پیش‌نویس (Draft)'}
            </Button>
            <Button
              type="button"
              variant="teal"
              loading={publishing}
              disabled={submitting || subjects.length === 0 || !isEditable}
              onClick={handleSubmitForReview}
            >
              {isEdit ? 'ذخیره و ارسال برای بررسی' : 'ذخیره و ارسال برای بررسی'}
            </Button>
          </div>
        </div>
      </div>

      {/* مودال انتخاب سوالات فعال از مخزن */}
      <Modal
        open={questionModalOpen}
        onClose={() => setQuestionModalOpen(false)}
        title="انتخاب سوالات از مخزن فعال (Active in Pool)"
        size="xl"
      >
        <div className="space-y-3">
          <FilterBar>
            <FilterInput
              value={qSearch}
              onChange={(e) => setQSearch(e.target.value)}
              placeholder="جستجو در متن سوال..."
              className="max-w-xs"
            />
            <FilterSelect value={qType} onChange={(e) => setQType(e.target.value)}>
              <option value="">نوع سوال: همه</option>
              <option value="single">چهارگزینه‌ای</option>
              <option value="multi">چندگزینه‌ای</option>
              <option value="true_false">صحیح/غلط</option>
              <option value="short_answer">پاسخ کوتاه</option>
            </FilterSelect>
            <FilterSelect value={qDifficulty} onChange={(e) => setQDifficulty(e.target.value)}>
              <option value="">دشواری: همه</option>
              <option value="easy">آسان</option>
              <option value="medium">متوسط</option>
              <option value="hard">سخت</option>
            </FilterSelect>
          </FilterBar>

          <div className="border border-line rounded-xl overflow-hidden max-h-80 overflow-y-auto">
            {availableLoading ? (
              <div className="p-8 text-center text-xs text-ink-soft">
                <Spinner size="sm" /> در حال دریافت سوالات فعال...
              </div>
            ) : availableQuestions.length === 0 ? (
              <div className="p-8 text-center text-xs text-ink-soft">
                هیچ سوال فعالی در این مضمون یافت نشد. سوالات ابتدا باید در بانک سوالات تایید و به مخزن اضافه شوند.
              </div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-panel-2 border-b border-line text-ink-soft font-semibold">
                  <tr>
                    <th className="p-2.5 text-right">متن سوال</th>
                    <th className="p-2.5 text-right">بخش</th>
                    <th className="p-2.5 text-right">نوع</th>
                    <th className="p-2.5 text-right">دشواری</th>
                    <th className="p-2.5 text-right">نمره</th>
                    <th className="p-2.5 text-center w-24">عملیات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {availableQuestions.map((q) => {
                    const isSelected = selectedQuestions.some((item) => item.id === q.id);
                    return (
                      <tr key={q.id} className="hover:bg-panel-2/50">
                        <td className="p-2.5 font-medium text-navy max-w-sm truncate">
                          {q.question_text}
                        </td>
                        <td className="p-2.5 text-ink-soft">{q.section_name}</td>
                        <td className="p-2.5">{TYPE_LABELS[q.question_type] || q.question_type}</td>
                        <td className="p-2.5">{DIFFICULTY_LABELS[q.difficulty] || q.difficulty}</td>
                        <td className="p-2.5 font-mono font-bold text-navy">{q.score ?? 1}</td>
                        <td className="p-2.5 text-center">
                          {isSelected ? (
                            <Badge tone="teal">انتخاب‌شده</Badge>
                          ) : (
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleAddQuestion(q)}
                            >
                              + افزودن
                            </Button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>

          <div className="flex justify-end pt-2">
            <Button variant="primary" onClick={() => setQuestionModalOpen(false)}>
              تأیید و بستن ({selectedQuestions.length} سوال انتخابی)
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
