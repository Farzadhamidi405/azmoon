import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select, Textarea } from '../ui/index.js';
import useSubjects from '../../hooks/useSubjects.js';

export default function ExamFormModal({ open, onClose, onSave, exam = null, busy = false }) {
  const { items: subjects } = useSubjects();
  const [formData, setFormData] = useState({
    title: '',
    subject_id: '',
    description: '',
    duration_minutes: 60,
    total_marks: 100,
    pass_marks: 60,
    randomize_questions: true,
    randomize_options: true,
    allow_back_navigation: true,
    show_result_immediately: false,
  });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (exam) {
      setFormData({
        title: exam.title || '',
        subject_id: exam.subject_id || '',
        description: exam.description || '',
        duration_minutes: exam.duration_minutes ?? 60,
        total_marks: exam.total_marks ?? 100,
        pass_marks: exam.pass_marks ?? 60,
        randomize_questions: !!exam.randomize_questions,
        randomize_options: !!exam.randomize_options,
        allow_back_navigation: !!exam.allow_back_navigation,
        show_result_immediately: !!exam.show_result_immediately,
      });
    } else {
      setFormData({
        title: '',
        subject_id: subjects[0]?.id || '',
        description: '',
        duration_minutes: 60,
        total_marks: 100,
        pass_marks: 60,
        randomize_questions: true,
        randomize_options: true,
        allow_back_navigation: true,
        show_result_immediately: false,
      });
    }
    setErrors({});
  }, [exam, open, subjects]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onSave(formData);
      onClose();
    } catch (err) {
      if (err.fieldErrors) {
        setErrors(err.fieldErrors);
      }
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={exam ? 'ویرایش آزمون' : 'ایجاد آزمون جدید'}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <Field label="عنوان آزمون" required error={errors.title}>
          <Input
            value={formData.title}
            onChange={(e) => handleChange('title', e.target.value)}
            placeholder="مثلاً: English Language — Batch A"
          />
        </Field>

        <Field label="مضمون" required error={errors.subject_id}>
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

        <Field label="توضیحات" error={errors.description}>
          <Textarea
            rows={2}
            value={formData.description}
            onChange={(e) => handleChange('description', e.target.value)}
            placeholder="توضیحات آزمون برای متقاضیان و اساتید"
          />
        </Field>

        <FieldRow cols={3}>
          <Field label="مدت زمان (دقیقه)" required error={errors.duration_minutes}>
            <Input
              type="number"
              min="1"
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

        <div className="border-t border-line pt-3">
          <div className="text-xs font-bold text-navy mb-2 uppercase">تنظیمات پیشرفته</div>
          <FieldRow cols={2}>
            <Field label="حرکت به سوال قبلی">
              <Select
                value={formData.allow_back_navigation ? '1' : '0'}
                onChange={(e) => handleChange('allow_back_navigation', e.target.value === '1')}
              >
                <option value="1">مجاز</option>
                <option value="0">غیرمجاز</option>
              </Select>
            </Field>

            <Field label="ترتیب تصادفی سوالات">
              <Select
                value={formData.randomize_questions ? '1' : '0'}
                onChange={(e) => handleChange('randomize_questions', e.target.value === '1')}
              >
                <option value="1">فعال</option>
                <option value="0">غیرفعال</option>
              </Select>
            </Field>
          </FieldRow>

          <FieldRow cols={2} className="mt-2">
            <Field label="ترتیب تصادفی گزینه‌ها">
              <Select
                value={formData.randomize_options ? '1' : '0'}
                onChange={(e) => handleChange('randomize_options', e.target.value === '1')}
              >
                <option value="1">فعال</option>
                <option value="0">غیرفعال</option>
              </Select>
            </Field>

            <Field label="نمایش آنی نتیجه بعد از اتمام">
              <Select
                value={formData.show_result_immediately ? '1' : '0'}
                onChange={(e) => handleChange('show_result_immediately', e.target.value === '1')}
              >
                <option value="0">خیر</option>
                <option value="1">بلی</option>
              </Select>
            </Field>
          </FieldRow>
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            {exam ? 'ذخیره تغییرات' : 'ایجاد آزمون'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
