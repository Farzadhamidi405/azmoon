import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select, Textarea } from '../ui/index.js';
import useSubjects from '../../hooks/useSubjects.js';

const INITIAL_OPTIONS = [
  { option_text: '', is_correct: true, order: 1 },
  { option_text: '', is_correct: false, order: 2 },
  { option_text: '', is_correct: false, order: 3 },
  { option_text: '', is_correct: false, order: 4 },
];

export default function QuestionFormModal({
  open,
  onClose,
  onSave,
  question = null,
  initialType = 'single_choice',
  busy = false,
}) {
  const { items: subjects } = useSubjects();
  const [formData, setFormData] = useState({
    subject_id: '',
    question_type: initialType,
    difficulty: 'medium',
    topic: '',
    points: 2,
    question_text: '',
    options: INITIAL_OPTIONS,
  });
  const [errors, setErrors] = useState({});

  useEffect(() => {
    if (question) {
      setFormData({
        subject_id: question.subject_id || '',
        question_type: question.question_type || 'single_choice',
        difficulty: question.difficulty || 'medium',
        topic: question.topic || '',
        points: question.points ?? 2,
        question_text: question.question_text || '',
        options:
          Array.isArray(question.options) && question.options.length > 0
            ? question.options.map((opt, i) => ({
                option_text: opt.option_text || '',
                is_correct: !!opt.is_correct,
                order: opt.order ?? i + 1,
              }))
            : INITIAL_OPTIONS,
      });
    } else {
      setFormData({
        subject_id: subjects[0]?.id || '',
        question_type: initialType || 'single_choice',
        difficulty: 'medium',
        topic: '',
        points: 2,
        question_text: '',
        options: INITIAL_OPTIONS,
      });
    }
    setErrors({});
  }, [question, initialType, open, subjects]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleOptionChange = (index, text) => {
    setFormData((prev) => {
      const next = [...prev.options];
      next[index] = { ...next[index], option_text: text };
      return { ...prev, options: next };
    });
  };

  const handleSelectCorrect = (index) => {
    setFormData((prev) => {
      const next = prev.options.map((opt, i) => ({
        ...opt,
        is_correct: prev.question_type === 'multiple_choice' ? (i === index ? !opt.is_correct : opt.is_correct) : i === index,
      }));
      return { ...prev, options: next };
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      await onSave(formData);
      onClose();
    } catch (err) {
      if (err.fieldErrors) setErrors(err.fieldErrors);
    }
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={question ? 'ویرایش سوال' : 'افزودن سوال جدید'}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <FieldRow cols={2}>
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

          <Field label="نوع سوال" required error={errors.question_type}>
            <Select
              value={formData.question_type}
              onChange={(e) => handleChange('question_type', e.target.value)}
            >
              <option value="single_choice">چهارگزینه‌ای (Single Choice)</option>
              <option value="multiple_choice">چندگزینه‌ای (Multiple Choice)</option>
              <option value="true_false">صحیح / غلط (True/False)</option>
              <option value="short_answer">پاسخ کوتاه (Short Answer)</option>
            </Select>
          </Field>
        </FieldRow>

        <Field label="متن سوال" required error={errors.question_text}>
          <Textarea
            rows={3}
            value={formData.question_text}
            onChange={(e) => handleChange('question_text', e.target.value)}
            placeholder="متن سوال را وارد کنید..."
          />
        </Field>

        {formData.question_type !== 'short_answer' && (
          <div className="border border-line rounded-lg p-3 bg-paper/40">
            <div className="text-xs font-bold text-navy mb-2">
              گزینه‌ها — گزینه(های) صحیح را علامت بزنید
            </div>
            <div className="space-y-2">
              {formData.options.map((opt, idx) => (
                <div key={idx} className="flex items-center gap-2">
                  <input
                    type={formData.question_type === 'multiple_choice' ? 'checkbox' : 'radio'}
                    name="correct_option"
                    checked={opt.is_correct}
                    onChange={() => handleSelectCorrect(idx)}
                    className="size-4 accent-navy cursor-pointer"
                  />
                  <Input
                    value={opt.option_text}
                    onChange={(e) => handleOptionChange(idx, e.target.value)}
                    placeholder={`گزینه ${idx + 1}`}
                    className={opt.is_correct ? 'border-navy font-semibold bg-panel' : ''}
                  />
                </div>
              ))}
            </div>
          </div>
        )}

        <FieldRow cols={3}>
          <Field label="سطح دشواری">
            <Select
              value={formData.difficulty}
              onChange={(e) => handleChange('difficulty', e.target.value)}
            >
              <option value="easy">آسان (Easy)</option>
              <option value="medium">متوسط (Medium)</option>
              <option value="hard">دشوار (Hard)</option>
            </Select>
          </Field>

          <Field label="موضوع (Topic)" error={errors.topic}>
            <Input
              value={formData.topic}
              onChange={(e) => handleChange('topic', e.target.value)}
              placeholder="مثلاً: Tenses, Prepositions"
            />
          </Field>

          <Field label="نمره سوال" required error={errors.points}>
            <Input
              type="number"
              min="1"
              value={formData.points}
              onChange={(e) => handleChange('points', Number(e.target.value))}
            />
          </Field>
        </FieldRow>

        <div className="flex justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            انصراف
          </Button>
          <Button type="submit" variant="primary" loading={busy}>
            {question ? 'ذخیره تغییرات' : 'ذخیره سوال'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
