import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Button,
  Field,
  FieldRow,
  Input,
  PageHeader,
  Select,
  Textarea,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import useSubjects from '../../hooks/useSubjects.js';
import { notifyError, notifySuccess, notifyWarning } from '../../lib/notify.js';

export default function ExamCreatePage() {
  const navigate = useNavigate();
  const { create, changeStatus } = useExams();
  const { items: subjects, loading: subjectsLoading } = useSubjects();

  const [submitting, setSubmitting] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [errors, setErrors] = useState({});

  // Form State matching Prototype v3 and Laravel Backend Validation
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

  const buildPayload = () => {
    if (!subjects.length) {
      return null;
    }

    const resolvedSubjectId = formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '');
    if (!resolvedSubjectId) {
      return null;
    }

    const totalMarks = Number(formData.total_marks) || 100;
    const totalQuestions = formData.sections.reduce((acc, s) => acc + (Number(s.count) || 0), 0) || 1;

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

    // تنظیم دقیق آخرین بخش برای رفع خطای احتمالی اعشار
    const currentSum = sectionsPayload.reduce((acc, s) => acc + s.total_marks, 0);
    if (sectionsPayload.length > 0 && Math.abs(currentSum - totalMarks) > 0.01) {
      const diff = Math.round((totalMarks - currentSum) * 100) / 100;
      sectionsPayload[sectionsPayload.length - 1].total_marks = Math.round((sectionsPayload[sectionsPayload.length - 1].total_marks + diff) * 100) / 100;
    }

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
      await create(payload);
      notifySuccess('آزمون به صورت پیش‌نویس (Draft) با موفقیت ذخیره شد.');
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
      // مرحله ۱: ایجاد آزمون با وضعیت اولیه draft
      const createdExam = await create(payload);

      // مرحله ۲: انتقال وضعیت مجاز به under_review (چرخه عمر قانونی آزمون)
      if (createdExam?.id) {
        try {
          await changeStatus(createdExam.id, 'under_review');
          notifySuccess('آزمون با موفقیت ایجاد و برای بررسی (Under Review) ارسال شد.');
        } catch (statusErr) {
          notifyWarning('آزمون به‌صورت پیش‌نویس (Draft) ذخیره شد، اما ارسال آن برای بررسی با خطا مواجه گردید.');
        }
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
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ایجاد آزمون.';
      notifyError(msg);
    } finally {
      setPublishing(false);
    }
  };

  return (
    <>
      <PageHeader
        title="ایجاد آزمون جدید"
        crumb={['آزمون‌ها', 'ایجاد جدید']}
      />

      <div className="max-w-[720px]">
        {!subjectsLoading && subjects.length === 0 && (
          <div className="p-3.5 mb-4 rounded-xl border border-gold/40 bg-gold/10 text-xs text-navy font-bold leading-relaxed">
            ⚠️ توجه: هیچ مضمونی در سامانه یافت نشد. برای ایجاد آزمون، لازم است ابتدا از منوی اطلاعات پایه &gt; مضامین، حداقل یک مضمون ثبت شود.
          </div>
        )}

        <div className="card space-y-4">
          <Field label="عنوان آزمون" required error={errors.title}>
            <Input
              value={formData.title}
              onChange={(e) => handleChange('title', e.target.value)}
              placeholder="مثلاً: English Language Examination"
            />
          </Field>

          <Field label="مضمون مربوطه" required error={errors.subject_id}>
            <Select
              value={formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '')}
              onChange={(e) => handleChange('subject_id', e.target.value)}
              disabled={subjects.length === 0}
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
              onChange={(e) => handleChange('description', e.target.value)}
              placeholder="توضیحات آزمون..."
            />
          </Field>

          <FieldRow cols={3}>
            <Field label="مدت زمان (دقیقه)" required error={errors.duration_minutes}>
              <Input
                type="number"
                min="1"
                max="480"
                value={formData.duration_minutes}
                onChange={(e) => handleChange('duration_minutes', Number(e.target.value))}
              />
            </Field>

            <Field label="نمره کل" required error={errors.total_marks}>
              <Input
                type="number"
                min="1"
                value={formData.total_marks}
                onChange={(e) => handleChange('total_marks', Number(e.target.value))}
              />
            </Field>

            <Field label="نمره قبولی" required error={errors.pass_marks}>
              <Input
                type="number"
                min="1"
                value={formData.pass_marks}
                onChange={(e) => handleChange('pass_marks', Number(e.target.value))}
              />
            </Field>
          </FieldRow>

          <FieldRow cols={2}>
            <Field label="تاریخ شروع (جهت نمایش در کارت و تقویم)">
              <Input
                value={formData.start_date}
                onChange={(e) => handleChange('start_date', e.target.value)}
                placeholder="۱۴۰۴/۰۶/۱۲ — ۰۸:۰۰"
              />
            </Field>

            <Field label="تاریخ پایان (جهت نمایش در کارت و تقویم)">
              <Input
                value={formData.end_date}
                onChange={(e) => handleChange('end_date', e.target.value)}
                placeholder="۱۴۰۴/۰۶/۱۲ — ۱۶:۰۰"
              />
            </Field>
          </FieldRow>

          <div className="section-title">بخش‌های آزمون</div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mb-2.5">
            {formData.sections.map((sec, idx) => (
              <div
                key={idx}
                className="stat p-3 rounded-xl border border-line bg-panel relative overflow-hidden"
                style={{ '--stat-c': sec.color }}
              >
                <div className="text-xs text-ink-soft">{sec.name}</div>
                <div className="text-base font-bold text-navy mt-1">{sec.count} سوال</div>
              </div>
            ))}
          </div>

          <Button type="button" variant="outline" size="sm" onClick={addSection}>
            + افزودن بخش جدید
          </Button>

          <div className="section-title">تنظیمات پیشرفته</div>
          <FieldRow cols={3}>
            <Field label="حرکت به سوال قبلی">
              <Select
                value={formData.allow_back_navigation ? 'yes' : 'no'}
                onChange={(e) => handleChange('allow_back_navigation', e.target.value === 'yes')}
              >
                <option value="yes">مجاز</option>
                <option value="no">غیرمجاز</option>
              </Select>
            </Field>

            <Field label="ترتیب تصادفی سوالات">
              <Select
                value={formData.randomize_questions ? 'yes' : 'no'}
                onChange={(e) => handleChange('randomize_questions', e.target.value === 'yes')}
              >
                <option value="yes">فعال</option>
                <option value="no">غیرفعال</option>
              </Select>
            </Field>

            <Field label="نمایش نتیجه بعد از اتمام">
              <Select
                value={formData.show_result_immediately ? 'yes' : 'no'}
                onChange={(e) => handleChange('show_result_immediately', e.target.value === 'yes')}
              >
                <option value="no">خیر</option>
                <option value="yes">بلی</option>
              </Select>
            </Field>
          </FieldRow>

          <div className="flex items-center gap-2.5 pt-4 border-t border-line">
            <Button
              type="button"
              variant="outline"
              loading={submitting}
              disabled={publishing || subjects.length === 0}
              onClick={handleSaveDraft}
            >
              ذخیره پیش‌نویس (Draft)
            </Button>
            <Button
              type="button"
              variant="teal"
              loading={publishing}
              disabled={submitting || subjects.length === 0}
              onClick={handleSubmitForReview}
            >
              ذخیره و ارسال برای بررسی
            </Button>
          </div>
        </div>
      </div>
    </>
  );
}
