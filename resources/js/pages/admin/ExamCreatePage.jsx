import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Button,
  Field,
  FieldRow,
  Input,
  PageHeader,
  Select,
} from '../../components/ui/index.js';
import useExams from '../../hooks/useExams.js';
import useSubjects from '../../hooks/useSubjects.js';
import { notifySuccess } from '../../lib/notify.js';

export default function ExamCreatePage() {
  const navigate = useNavigate();
  const { create } = useExams();
  const { items: subjects } = useSubjects();

  const [step, setStep] = useState(1); // 1: Details, 2: Sections, 3: Confirmation
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  // Form State
  const [formData, setFormData] = useState({
    title: '',
    code: '',
    subject_id: '',
    total_score: 100,
    duration_minutes: 60,
    passing_percentage: 50,
    disconnect_grace_period_seconds: 120,
    sections: [
      { name: 'بخش اول: گرامر و واژگان', question_count: 20, points: 40 },
      { name: 'بخش دوم: درک مطلب (Reading)', question_count: 15, points: 30 },
      { name: 'بخش سوم: شنیداری (Listening)', question_count: 15, points: 30 },
    ],
  });

  const handleChange = (field, val) => {
    setFormData((p) => ({ ...p, [field]: val }));
    setErrors((p) => ({ ...p, [field]: null }));
  };

  const handleSectionChange = (index, field, val) => {
    setFormData((p) => {
      const nextSections = [...p.sections];
      nextSections[index] = { ...nextSections[index], [field]: val };
      return { ...p, sections: nextSections };
    });
  };

  const addSection = () => {
    setFormData((p) => ({
      ...p,
      sections: [
        ...p.sections,
        { name: `بخش جدید ${p.sections.length + 1}`, question_count: 10, points: 20 },
      ],
    }));
  };

  const removeSection = (index) => {
    if (formData.sections.length <= 1) return;
    setFormData((p) => ({
      ...p,
      sections: p.sections.filter((_, i) => i !== index),
    }));
  };

  const validateStep1 = () => {
    const errs = {};
    if (!formData.title.trim()) errs.title = 'وارد کردن عنوان آزمون الزامی است.';
    if (!formData.code.trim()) errs.code = 'کد آزمون الزامی است.';
    if (!formData.subject_id) errs.subject_id = 'انتخاب مضمون الزامی است.';
    if (formData.duration_minutes < 5) errs.duration_minutes = 'حداقل مدت آزمون ۵ دقیقه است.';
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleNext = () => {
    if (step === 1) {
      if (!validateStep1()) return;
      setStep(2);
    } else if (step === 2) {
      setStep(3);
    }
  };

  const handleSubmit = async () => {
    setSubmitting(true);
    try {
      await create({
        title: formData.title,
        code: formData.code,
        subject_id: formData.subject_id,
        total_score: Number(formData.total_score),
        duration_minutes: Number(formData.duration_minutes),
        passing_percentage: Number(formData.passing_percentage),
        disconnect_grace_period_seconds: Number(formData.disconnect_grace_period_seconds),
      });
      notifySuccess('آزمون جدید با موفقیت ایجاد شد.');
      navigate('/admin/exams');
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    } finally {
      setSubmitting(false);
    }
  };

  const selectedSubject = subjects.find((s) => String(s.id) === String(formData.subject_id));
  const totalSectionPoints = formData.sections.reduce((acc, s) => acc + Number(s.points || 0), 0);
  const totalSectionQuestions = formData.sections.reduce((acc, s) => acc + Number(s.question_count || 0), 0);

  return (
    <>
      <PageHeader
        title="ایجاد آزمون جدید (طرح سه‌مرحله‌ای)"
        crumb={['آزمون‌ها', 'ایجاد آزمون']}
      />

      <div className="max-w-4xl mx-auto space-y-6">
        {/* Stepper Wizard Header */}
        <div className="card p-4 bg-panel flex items-center justify-between border-line">
          <div className="flex items-center gap-3">
            <span
              className={`size-8 rounded-full flex items-center justify-center font-bold text-sm ${
                step >= 1 ? 'bg-navy text-panel' : 'bg-paper text-ink-soft'
              }`}
            >
              ۱
            </span>
            <div className="text-xs">
              <div className="font-bold text-navy">مرحله اول: مشخصات پایه</div>
              <div className="text-ink-soft">عنوان، کد و تنظیمات زمانی</div>
            </div>
          </div>

          <div className="w-12 h-0.5 bg-line hidden sm:block" />

          <div className="flex items-center gap-3">
            <span
              className={`size-8 rounded-full flex items-center justify-center font-bold text-sm ${
                step >= 2 ? 'bg-navy text-panel' : 'bg-paper text-ink-soft'
              }`}
            >
              ۲
            </span>
            <div className="text-xs">
              <div className="font-bold text-navy">مرحله دوم: بخش‌های آزمون</div>
              <div className="text-ink-soft">تعریف سرفصل‌ها و بارم‌بندی</div>
            </div>
          </div>

          <div className="w-12 h-0.5 bg-line hidden sm:block" />

          <div className="flex items-center gap-3">
            <span
              className={`size-8 rounded-full flex items-center justify-center font-bold text-sm ${
                step === 3 ? 'bg-teal text-panel' : 'bg-paper text-ink-soft'
              }`}
            >
              ۳
            </span>
            <div className="text-xs">
              <div className="font-bold text-navy">مرحله سوم: تأیید نهایی</div>
              <div className="text-ink-soft">بازبینی و ثبت قطعی آزمون</div>
            </div>
          </div>
        </div>

        {/* Step 1: Details */}
        {step === 1 && (
          <div className="card p-6 space-y-4">
            <h3 className="font-bold text-navy text-sm border-b border-line pb-2">
              ۱. مشخصات عمومی و زمانی آزمون
            </h3>

            <FieldRow cols={2}>
              <Field label="عنوان رسمی آزمون" required error={errors.title}>
                <Input
                  value={formData.title}
                  onChange={(e) => handleChange('title', e.target.value)}
                  placeholder="مثلاً: امتحان ورودی فاکولته کمپیوتر ساینس — بهار ۱۴۰۵"
                />
              </Field>

              <Field label="کد آزمون" required error={errors.code}>
                <Input
                  value={formData.code}
                  onChange={(e) => handleChange('code', e.target.value)}
                  placeholder="EXAM-2026-CS"
                />
              </Field>
            </FieldRow>

            <FieldRow cols={2}>
              <Field label="مضمون مربوطه" required error={errors.subject_id}>
                <Select
                  value={formData.subject_id}
                  onChange={(e) => handleChange('subject_id', e.target.value)}
                >
                  <option value="">انتخاب مضمون...</option>
                  {subjects.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name} ({s.code})
                    </option>
                  ))}
                </Select>
              </Field>

              <Field label="مدت زمان برگزاری (دقیقه)" required error={errors.duration_minutes}>
                <Input
                  type="number"
                  min="5"
                  max="360"
                  value={formData.duration_minutes}
                  onChange={(e) => handleChange('duration_minutes', Number(e.target.value))}
                />
              </Field>
            </FieldRow>

            <FieldRow cols={3}>
              <Field label="نمره کل آزمون" required error={errors.total_score}>
                <Input
                  type="number"
                  value={formData.total_score}
                  onChange={(e) => handleChange('total_score', Number(e.target.value))}
                />
              </Field>

              <Field label="درصد قبولی (Pass %)">
                <Input
                  type="number"
                  min="1"
                  max="100"
                  value={formData.passing_percentage}
                  onChange={(e) => handleChange('passing_percentage', Number(e.target.value))}
                />
              </Field>

              <Field label="مهلت قطعی شبکه (ثانیه)">
                <Input
                  type="number"
                  value={formData.disconnect_grace_period_seconds}
                  onChange={(e) => handleChange('disconnect_grace_period_seconds', Number(e.target.value))}
                />
              </Field>
            </FieldRow>

            <div className="flex justify-end gap-2 pt-4 border-t border-line">
              <Button variant="outline" onClick={() => navigate('/admin/exams')}>
                انصراف
              </Button>
              <Button variant="primary" onClick={handleNext}>
                گام بعدی: تعریف بخش‌ها →
              </Button>
            </div>
          </div>
        )}

        {/* Step 2: Sections */}
        {step === 2 && (
          <div className="card p-6 space-y-4">
            <div className="flex justify-between items-center border-b border-line pb-2">
              <h3 className="font-bold text-navy text-sm">۲. بخش‌ها و بارم‌بندی آزمون (Sections)</h3>
              <Button size="sm" variant="outline" onClick={addSection}>
                + افزودن بخش جدید
              </Button>
            </div>

            <div className="space-y-3">
              {formData.sections.map((sec, idx) => (
                <div key={idx} className="p-4 border border-line rounded-xl bg-paper/50 flex flex-col sm:flex-row items-center gap-3">
                  <div className="flex-1 w-full sm:w-auto">
                    <label className="text-xs text-ink-soft block mb-1">نام سرفصل / بخش</label>
                    <Input
                      value={sec.name}
                      onChange={(e) => handleSectionChange(idx, 'name', e.target.value)}
                    />
                  </div>
                  <div className="w-full sm:w-32">
                    <label className="text-xs text-ink-soft block mb-1">تعداد سوالات</label>
                    <Input
                      type="number"
                      min="1"
                      value={sec.question_count}
                      onChange={(e) => handleSectionChange(idx, 'question_count', Number(e.target.value))}
                    />
                  </div>
                  <div className="w-full sm:w-32">
                    <label className="text-xs text-ink-soft block mb-1">سهم نمره</label>
                    <Input
                      type="number"
                      min="1"
                      value={sec.points}
                      onChange={(e) => handleSectionChange(idx, 'points', Number(e.target.value))}
                    />
                  </div>
                  {formData.sections.length > 1 && (
                    <div className="pt-5">
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-red"
                        onClick={() => removeSection(idx)}
                      >
                        حذف
                      </Button>
                    </div>
                  )}
                </div>
              ))}
            </div>

            <div className="p-3 bg-panel border border-line rounded-xl flex justify-between text-xs">
              <span>
                مجموع سوالات برنامه‌ریزی‌شده: <b>{totalSectionQuestions} سوال</b>
              </span>
              <span>
                مجموع بارم بخش‌ها: <b>{totalSectionPoints} نمره</b> (نمره کل آزمون: {formData.total_score})
              </span>
            </div>

            <div className="flex justify-between pt-4 border-t border-line">
              <Button variant="outline" onClick={() => setStep(1)}>
                ← بازگشت به مشخصات
              </Button>
              <Button variant="primary" onClick={handleNext}>
                گام بعدی: تأیید نهایی →
              </Button>
            </div>
          </div>
        )}

        {/* Step 3: Confirmation */}
        {step === 3 && (
          <div className="card p-6 space-y-6">
            <h3 className="font-bold text-navy text-sm border-b border-line pb-2">
              ۳. پیش‌نمایش و تأیید نهایی ایجاد آزمون
            </h3>

            <div className="grid grid-cols-2 gap-4 text-xs">
              <div className="p-3 bg-paper rounded-xl space-y-2">
                <div className="font-bold text-navy">مشخصات آزمون:</div>
                <div>عنوان: <b>{formData.title}</b></div>
                <div>کد: <b className="font-mono">{formData.code}</b></div>
                <div>مضمون: <b>{selectedSubject?.name || 'انتخاب نشده'}</b></div>
              </div>

              <div className="p-3 bg-paper rounded-xl space-y-2">
                <div className="font-bold text-navy">تنظیمات زمانی و نمره:</div>
                <div>مدت آزمون: <b>{formData.duration_minutes} دقیقه</b></div>
                <div>نمره کل: <b>{formData.total_score} نمره</b></div>
                <div>شرط قبولی: <b>{formData.passing_percentage}%</b></div>
              </div>
            </div>

            <div>
              <div className="font-bold text-navy text-xs mb-2">بخش‌های تعریف‌شده:</div>
              <ul className="text-xs space-y-1 bg-paper/60 p-3 rounded-xl border border-line">
                {formData.sections.map((s, i) => (
                  <li key={i} className="flex justify-between">
                    <span>{s.name}</span>
                    <span className="font-mono">{s.question_count} سوال | {s.points} نمره</span>
                  </li>
                ))}
              </ul>
            </div>

            <div className="text-xs text-ink-soft bg-gold-soft/30 p-3 rounded-xl border border-gold/20 leading-relaxed">
              پس از ایجاد، آزمون در وضعیت «پیش‌نویس (Draft)» قرار می‌گیرد. برای افزودن سوالات، تخصیص صندلی‌ها به متقاضیان و انتشار، از صفحه مدیریت آزمون‌ها اقدام فرمایید.
            </div>

            <div className="flex justify-between pt-4 border-t border-line">
              <Button variant="outline" onClick={() => setStep(2)}>
                ← ویرایش بخش‌ها
              </Button>
              <Button
                variant="teal"
                size="lg"
                loading={submitting}
                onClick={handleSubmit}
              >
                تأیید و ثبت نهایی آزمون
              </Button>
            </div>
          </div>
        )}
      </div>
    </>
  );
}
