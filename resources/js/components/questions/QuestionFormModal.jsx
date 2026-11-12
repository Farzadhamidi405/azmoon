import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select, Textarea } from '../ui/index.js';
import useSubjects from '../../hooks/useSubjects.js';

const INITIAL_OPTIONS = [
  { option_text: '', is_correct: true, display_order: 0 },
  { option_text: '', is_correct: false, display_order: 1 },
  { option_text: '', is_correct: false, display_order: 2 },
  { option_text: '', is_correct: false, display_order: 3 },
];

export default function QuestionFormModal({
  open,
  onClose,
  onSave,
  question = null,
  initialSection = 'Grammar',
  initialType = 'single_choice',
  busy = false,
}) {
  const { items: subjects } = useSubjects();
  const [formData, setFormData] = useState({
    subject_id: '',
    section_name: initialSection || 'Grammar',
    question_type: initialType || 'single_choice',
    difficulty: 'medium',
    topic: '',
    score: 2,
    question_text: '',
    options: INITIAL_OPTIONS,
  });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (question) {
      setFormData({
        subject_id: question.subject_id ? String(question.subject_id) : '',
        section_name: question.section_name || initialSection || 'Grammar',
        question_type: question.question_type || 'single_choice',
        difficulty: question.difficulty || 'medium',
        topic: question.topic || '',
        score: question.score ?? question.points ?? 2,
        question_text: question.question_text || '',
        options:
          Array.isArray(question.options) && question.options.length > 0
            ? question.options.map((opt, i) => ({
                option_text: opt.option_text || '',
                is_correct: Boolean(opt.is_correct),
                display_order: opt.display_order ?? i,
              }))
            : INITIAL_OPTIONS,
      });
    } else {
      setFormData({
        subject_id: subjects[0]?.id ? String(subjects[0].id) : '',
        section_name: initialSection || 'Grammar',
        question_type: initialType || 'single_choice',
        difficulty: 'medium',
        topic: initialSection === 'Grammar' ? 'Tenses' : '',
        score: 2,
        question_text: '',
        options: INITIAL_OPTIONS,
      });
    }
    setErrors({});
  }, [question, initialSection, initialType, open, subjects]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleOptionTextChange = (index, text) => {
    setFormData((prev) => {
      const next = [...prev.options];
      next[index] = { ...next[index], option_text: text };
      return { ...prev, options: next };
    });
  };

  const handleSelectCorrect = (index) => {
    setFormData((prev) => {
      const isMulti = prev.question_type === 'multiple_choice';
      const next = prev.options.map((opt, i) => ({
        ...opt,
        is_correct: isMulti ? (i === index ? !opt.is_correct : opt.is_correct) : i === index,
      }));
      return { ...prev, options: next };
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setErrors({});

    const resolvedSubjectId = formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '');
    if (!resolvedSubjectId) {
      setErrors({ subject_id: 'انتخاب مضمون الزامی است.' });
      return;
    }

    const payload = {
      subject_id: Number(resolvedSubjectId),
      section_name: formData.section_name || 'Grammar',
      question_type: formData.question_type || 'single_choice',
      question_text: formData.question_text.trim(),
      difficulty: formData.difficulty || 'medium',
      topic: formData.topic?.trim() || null,
      score: Number(formData.score) || 1,
      options:
        formData.question_type !== 'short_answer'
          ? formData.options.map((opt, idx) => ({
              option_text: opt.option_text.trim(),
              is_correct: Boolean(opt.is_correct),
              display_order: idx,
            }))
          : [],
    };

    // Client-side quick check
    if (!payload.question_text) {
      setErrors({ question_text: 'متن سوال الزامی است.' });
      return;
    }
    if (payload.question_type !== 'short_answer') {
      const emptyOptions = payload.options.some((o) => !o.option_text);
      if (emptyOptions) {
        setErrors({ options: 'متن تمام گزینه‌ها باید وارد شود.' });
        return;
      }
      const hasCorrect = payload.options.some((o) => o.is_correct);
      if (!hasCorrect) {
        setErrors({ options: 'حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.' });
        return;
      }
    }

    try {
      await onSave(payload);
      onClose();
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
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={question ? 'ویرایش سوال' : `افزودن سوال — ${formData.section_name}`}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <FieldRow cols={2}>
          <Field label="مضمون" required error={errors.subject_id}>
            <Select
              value={formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '')}
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

          <Field label="بخش آزمون (Section Name)" required error={errors.section_name}>
            <Select
              value={formData.section_name}
              onChange={(e) => handleChange('section_name', e.target.value)}
            >
              <option value="Grammar">Grammar (گرامر)</option>
              <option value="Vocabulary">Vocabulary (واژگان)</option>
              <option value="Reading">Reading (درک مطلب)</option>
              <option value="Listening">Listening (شنیداری)</option>
            </Select>
          </Field>
        </FieldRow>

        <FieldRow cols={2}>
          <Field label="نوع سوال" required error={errors.question_type}>
            <Select
              value={formData.question_type}
              onChange={(e) => handleChange('question_type', e.target.value)}
            >
              <option value="single_choice">چهارگزینه‌ای (تک‌پاسخی)</option>
              <option value="multiple_choice">چندگزینه‌ای (چندپاسخی)</option>
              <option value="true_false">صحیح / غلط (True/False)</option>
              <option value="short_answer">پاسخ کوتاه (Short Answer)</option>
            </Select>
          </Field>

          <Field label="موضوع (Topic)" error={errors.topic}>
            <Input
              value={formData.topic}
              onChange={(e) => handleChange('topic', e.target.value)}
              placeholder="مثلاً: Tenses, Prepositions, Academic"
            />
          </Field>
        </FieldRow>

        <Field label="متن سوال" required error={errors.question_text}>
          <Textarea
            rows={2}
            value={formData.question_text}
            onChange={(e) => handleChange('question_text', e.target.value)}
            placeholder="She _____ to university every day."
          />
        </Field>

        {formData.question_type !== 'short_answer' && (
          <div className="border border-line rounded-lg p-3 bg-paper/40 space-y-2">
            <div className="text-xs font-bold text-navy mb-1">
              گزینه‌ها — جواب صحیح را انتخاب کنید:
            </div>
            {errors.options && <div className="text-xs text-red">{errors.options}</div>}

            <div className="space-y-2">
              {formData.options.map((opt, idx) => {
                const labelLetter = String.fromCharCode(65 + idx); // A, B, C, D
                return (
                  <div
                    key={idx}
                    className={`flex items-center gap-3 p-2.5 rounded-lg border transition-all cursor-pointer ${
                      opt.is_correct
                        ? 'border-navy bg-navy/5 font-semibold'
                        : 'border-line hover:border-navy/40 bg-panel'
                    }`}
                    onClick={() => handleSelectCorrect(idx)}
                  >
                    <input
                      type={formData.question_type === 'multiple_choice' ? 'checkbox' : 'radio'}
                      name="correct_option"
                      checked={opt.is_correct}
                      onChange={() => handleSelectCorrect(idx)}
                      className="size-4 accent-navy cursor-pointer"
                    />
                    <span className="font-mono text-xs font-bold text-navy w-5">{labelLetter}.</span>
                    <Input
                      value={opt.option_text}
                      onChange={(e) => {
                        e.stopPropagation();
                        handleOptionTextChange(idx, e.target.value);
                      }}
                      placeholder={`متن گزینه ${labelLetter}`}
                      className="flex-1"
                    />
                  </div>
                );
              })}
            </div>
          </div>
        )}

        <FieldRow cols={2}>
          <Field label="سطح دشواری" error={errors.difficulty}>
            <Select
              value={formData.difficulty}
              onChange={(e) => handleChange('difficulty', e.target.value)}
            >
              <option value="easy">آسان</option>
              <option value="medium">متوسط</option>
              <option value="hard">سخت</option>
            </Select>
          </Field>

          <Field label="نمره سوال (Score)" required error={errors.score}>
            <Input
              type="number"
              min="0.5"
              step="0.5"
              value={formData.score}
              onChange={(e) => handleChange('score', Number(e.target.value))}
            />
          </Field>
        </FieldRow>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            {question ? 'ذخیره تغییرات' : 'ذخیره و ثبت سوال'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
