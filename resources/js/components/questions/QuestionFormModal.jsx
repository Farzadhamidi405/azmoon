import { useEffect, useState } from 'react';
import { Button, Field, FieldRow, Input, Modal, Select, Textarea } from '../ui/index.js';
import useSubjects from '../../hooks/useSubjects.js';
import { api, API_BASE } from '../../lib/api.js';
import { notifyError, notifySuccess } from '../../lib/notify.js';

const DEFAULT_SINGLE_OPTIONS = [
  { option_text: '', is_correct: true, display_order: 0 },
  { option_text: '', is_correct: false, display_order: 1 },
  { option_text: '', is_correct: false, display_order: 2 },
  { option_text: '', is_correct: false, display_order: 3 },
];

const DEFAULT_TF_OPTIONS = [
  { option_text: 'صحیح (True)', is_correct: true, display_order: 0 },
  { option_text: 'غلط (False)', is_correct: false, display_order: 1 },
];

export default function QuestionFormModal({
  open,
  onClose,
  onSave,
  question = null,
  initialSection = 'Grammar',
  initialType = 'single',
  busy = false,
}) {
  const { items: subjects } = useSubjects();

  const [formData, setFormData] = useState({
    subject_id: '',
    section_name: 'Grammar',
    question_type: 'single',
    difficulty: 'medium',
    topic: '',
    score: 2,
    question_text: '',
    passage_id: '',
    media_id: '',
    short_answer_key: '',
    options: DEFAULT_SINGLE_OPTIONS,
  });

  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  // Passages for Reading section
  const [passages, setPassages] = useState([]);
  const [newPassageTitle, setNewPassageTitle] = useState('');
  const [newPassageText, setNewPassageText] = useState('');
  const [creatingPassage, setCreatingPassage] = useState(false);

  // Audio Upload for Listening section
  const [audioFile, setAudioFile] = useState(null);
  const [uploadingAudio, setUploadingAudio] = useState(false);
  const [uploadedAudioName, setUploadedAudioName] = useState('');

  // بارگذاری داده‌های اولیه فقط هنگام باز شدن مودال یا تغییر question
  useEffect(() => {
    if (!open) return;

    if (question) {
      let qType = question.question_type || 'single';
      if (qType === 'single_choice') qType = 'single';
      if (qType === 'multiple_choice') qType = 'multi';

      let opts = [];
      let shortKey = '';

      if (qType === 'short_answer') {
        if (Array.isArray(question.options) && question.options.length > 0) {
          shortKey = question.options[0]?.option_text || '';
        }
      } else if (Array.isArray(question.options) && question.options.length > 0) {
        opts = question.options.map((opt, i) => ({
          option_text: opt.option_text || '',
          is_correct: Boolean(opt.is_correct),
          display_order: opt.display_order ?? i,
        }));
      } else if (qType === 'true_false') {
        opts = DEFAULT_TF_OPTIONS;
      } else {
        opts = DEFAULT_SINGLE_OPTIONS;
      }

      setFormData({
        subject_id: question.subject_id ? String(question.subject_id) : (subjects[0]?.id ? String(subjects[0].id) : ''),
        section_name: question.section_name || initialSection || 'Grammar',
        question_type: qType,
        difficulty: question.difficulty || 'medium',
        topic: question.topic || '',
        score: question.score ?? question.points ?? 2,
        question_text: question.question_text || '',
        passage_id: question.passage_id ? String(question.passage_id) : '',
        media_id: question.media_id ? String(question.media_id) : '',
        short_answer_key: shortKey,
        options: opts,
      });
    } else {
      const qType = initialType || 'single';
      const sec = initialSection || 'Grammar';
      let opts = DEFAULT_SINGLE_OPTIONS;
      if (qType === 'true_false') {
        opts = DEFAULT_TF_OPTIONS;
      } else if (qType === 'short_answer') {
        opts = [];
      }

      setFormData({
        subject_id: subjects[0]?.id ? String(subjects[0].id) : '',
        section_name: sec,
        question_type: qType,
        difficulty: 'medium',
        topic: sec === 'Grammar' ? 'Tenses' : '',
        score: 2,
        question_text: '',
        passage_id: '',
        media_id: '',
        short_answer_key: '',
        options: opts,
      });
    }

    setAudioFile(null);
    setUploadedAudioName('');
    setNewPassageTitle('');
    setNewPassageText('');
    setErrors({});
  }, [open, question, initialSection, initialType]);

  // اگر لیست مضامین بعد از باز شدن مودال لود شد، در صورت خالی بودن subject_id به آرامی تنظیم شود
  useEffect(() => {
    if (open && !formData.subject_id && subjects.length > 0) {
      setFormData((prev) => ({
        ...prev,
        subject_id: prev.subject_id || String(subjects[0].id),
      }));
    }
  }, [open, subjects, formData.subject_id]);

  // بارگذاری متن‌های درک مطلب زمانی که بخش Reading است
  useEffect(() => {
    if (open && formData.section_name === 'Reading') {
      const subjectId = formData.subject_id || subjects[0]?.id;
      api
        .get(`${API_BASE}/admin/passages`, { params: { subject_id: subjectId, per_page: 50 }, skipErrorToast: true })
        .then((res) => {
          const list = res.data?.data?.data || res.data?.data || [];
          setPassages(Array.isArray(list) ? list : []);
        })
        .catch(() => setPassages([]));
    }
  }, [open, formData.section_name, formData.subject_id, subjects]);

  const handleChange = (field, value) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => ({ ...prev, [field]: null }));
  };

  const handleTypeChange = (newType) => {
    setFormData((prev) => {
      let nextOptions = prev.options;
      if (newType === 'true_false') {
        nextOptions = DEFAULT_TF_OPTIONS;
      } else if (newType === 'short_answer') {
        nextOptions = [];
      } else if (prev.question_type === 'true_false' || prev.question_type === 'short_answer' || prev.options.length < 2) {
        nextOptions = DEFAULT_SINGLE_OPTIONS;
      } else if (newType === 'single') {
        // اطمینان از اینکه دقیقاً یکی صحیح باشد
        const hasCorrect = prev.options.some((o) => o.is_correct);
        nextOptions = prev.options.map((opt, i) => ({
          ...opt,
          is_correct: hasCorrect ? opt.is_correct : i === 0,
        }));
      }
      return {
        ...prev,
        question_type: newType,
        options: nextOptions,
      };
    });
    setErrors((prev) => ({ ...prev, question_type: null, options: null }));
  };

  const handleOptionTextChange = (index, text) => {
    setFormData((prev) => {
      const next = [...prev.options];
      next[index] = { ...next[index], option_text: text };
      return { ...prev, options: next };
    });
    setErrors((prev) => ({
      ...prev,
      options: null,
      [`options.${index}.option_text`]: null,
    }));
  };

  const handleSelectCorrect = (index) => {
    setFormData((prev) => {
      const isMulti = prev.question_type === 'multi';
      const next = prev.options.map((opt, i) => ({
        ...opt,
        is_correct: isMulti ? (i === index ? !opt.is_correct : opt.is_correct) : i === index,
      }));
      return { ...prev, options: next };
    });
    setErrors((prev) => ({ ...prev, options: null }));
  };

  const handleAddOption = () => {
    setFormData((prev) => {
      if (prev.options.length >= 8) return prev;
      const next = [
        ...prev.options,
        {
          option_text: '',
          is_correct: false,
          display_order: prev.options.length,
        },
      ];
      return { ...prev, options: next };
    });
  };

  const handleRemoveOption = (index) => {
    setFormData((prev) => {
      if (prev.options.length <= 2) return prev;
      const filtered = prev.options.filter((_, i) => i !== index);
      const reIndexed = filtered.map((opt, i) => ({
        ...opt,
        display_order: i,
      }));
      // اگر همه گزینه‌های صحیح حذف شدند، اولین گزینه صحیح شود
      const hasCorrect = reIndexed.some((o) => o.is_correct);
      if (!hasCorrect && reIndexed.length > 0 && prev.question_type !== 'short_answer') {
        reIndexed[0].is_correct = true;
      }
      return { ...prev, options: reIndexed };
    });
  };

  // ایجاد سریع متن درک مطلب جدید
  const handleCreatePassage = async () => {
    if (!newPassageTitle.trim() || !newPassageText.trim()) {
      notifyError('لطفاً عنوان و متن درک مطلب را وارد نمایید.');
      return;
    }
    setCreatingPassage(true);
    try {
      const subjectId = formData.subject_id || subjects[0]?.id;
      const res = await api.post(`${API_BASE}/admin/passages`, {
        subject_id: Number(subjectId),
        title: newPassageTitle.trim(),
        body_text: newPassageText.trim(),
      });
      const created = res.data?.data;
      if (created?.id) {
        setPassages((prev) => [created, ...prev]);
        handleChange('passage_id', String(created.id));
        setNewPassageTitle('');
        setNewPassageText('');
        notifySuccess('متن درک مطلب ذخیره شد.');
      }
    } catch (err) {
      notifyError(err.response?.data?.message || 'خطا در ذخیره متن درک مطلب.');
    } finally {
      setCreatingPassage(false);
    }
  };

  // آپلود فایل صوتی سوال Listening
  const handleUploadAudio = async () => {
    if (!audioFile) {
      notifyError('لطفاً یک فایل صوتی انتخاب کنید.');
      return;
    }
    setUploadingAudio(true);
    const data = new FormData();
    data.append('file', audioFile);
    data.append('type', 'audio');
    data.append('allow_replay', '0');
    data.append('max_replay_count', '1');

    try {
      const res = await api.post(`${API_BASE}/admin/media`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const media = res.data?.data;
      if (media?.id) {
        handleChange('media_id', String(media.id));
        setUploadedAudioName(audioFile.name);
        notifySuccess('فایل صوتی با موفقیت پیوست شد.');
      }
    } catch (err) {
      notifyError(err.response?.data?.message || 'خطا در بارگذاری فایل صوتی.');
    } finally {
      setUploadingAudio(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (busy || submitting) return;
    setErrors({});

    const resolvedSubjectId = formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '');
    if (!resolvedSubjectId) {
      setErrors({ subject_id: 'انتخاب مضمون الزامی است.' });
      return;
    }

    if (!formData.question_text?.trim()) {
      setErrors({ question_text: 'متن سوال الزامی است.' });
      return;
    }

    const numScore = Number(formData.score);
    if (isNaN(numScore) || numScore <= 0) {
      setErrors({ score: 'نمره سوال باید عددی مثبت باشد.' });
      return;
    }

    let finalOptions = [];
    if (formData.question_type === 'short_answer') {
      if (formData.short_answer_key?.trim()) {
        finalOptions = [
          {
            option_text: formData.short_answer_key.trim(),
            is_correct: true,
            display_order: 0,
          },
        ];
      } else {
        finalOptions = [];
      }
    } else {
      const emptyOption = formData.options.some((o) => !o.option_text?.trim());
      if (emptyOption) {
        setErrors({ options: 'متن تمام گزینه‌ها باید وارد شود.' });
        return;
      }

      const hasCorrect = formData.options.some((o) => o.is_correct);
      if (!hasCorrect) {
        setErrors({ options: 'حداقل یک گزینه را به عنوان پاسخ صحیح انتخاب کنید.' });
        return;
      }

      if (formData.question_type === 'single' || formData.question_type === 'true_false') {
        const correctCount = formData.options.filter((o) => o.is_correct).length;
        if (correctCount !== 1) {
          setErrors({ options: 'برای این نوع سوال باید دقیقاً یک گزینه صحیح انتخاب شود.' });
          return;
        }
      }

      finalOptions = formData.options.map((opt, idx) => ({
        option_text: opt.option_text.trim(),
        is_correct: Boolean(opt.is_correct),
        display_order: idx,
      }));
    }

    const payload = {
      subject_id: Number(resolvedSubjectId),
      section_name: formData.section_name || 'Grammar',
      question_type: formData.question_type || 'single',
      question_text: formData.question_text.trim(),
      difficulty: formData.difficulty || 'medium',
      topic: formData.topic?.trim() || null,
      score: numScore,
      passage_id: formData.passage_id ? Number(formData.passage_id) : null,
      media_id: formData.media_id ? Number(formData.media_id) : null,
      options: finalOptions,
    };

    setSubmitting(true);
    try {
      await onSave(payload);
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
    } finally {
      setSubmitting(false);
    }
  };

  const isSaving = busy || submitting;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={question ? 'ویرایش سوال' : `افزودن سوال جدید (${formData.section_name})`}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <FieldRow cols={2}>
          <Field label="مضمون (Subject)" required error={errors.subject_id}>
            <Select
              value={formData.subject_id || (subjects[0]?.id ? String(subjects[0].id) : '')}
              onChange={(e) => handleChange('subject_id', e.target.value)}
              disabled={isSaving}
            >
              {subjects.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name} ({s.code})
                </option>
              ))}
            </Select>
          </Field>

          <Field label="بخش آزمون (Section)" required error={errors.section_name}>
            <Select
              value={formData.section_name}
              onChange={(e) => handleChange('section_name', e.target.value)}
              disabled={isSaving}
            >
              <option value="Grammar">Grammar</option>
              <option value="Vocabulary">Vocabulary</option>
              <option value="Reading">Reading</option>
              <option value="Listening">Listening</option>
            </Select>
          </Field>
        </FieldRow>

        {/* بخش ویژه Listening: انتخاب و بارگذاری صوت */}
        {formData.section_name === 'Listening' && (
          <div className="p-3.5 rounded-xl border border-line bg-paper/60 space-y-2">
            <div className="text-xs font-bold text-navy">فایل صوتی سوال (Audio File)</div>
            <div className="flex items-center gap-2">
              <input
                type="file"
                accept="audio/*"
                onChange={(e) => setAudioFile(e.target.files?.[0] || null)}
                disabled={isSaving || uploadingAudio}
                className="text-xs file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-navy file:text-white"
              />
              <Button
                type="button"
                size="sm"
                variant="outline"
                loading={uploadingAudio}
                disabled={isSaving}
                onClick={handleUploadAudio}
              >
                بارگذاری صوت
              </Button>
            </div>
            {formData.media_id && (
              <div className="flex items-center justify-between text-xs text-teal font-semibold pt-1">
                <span>
                  ✓ فایل صوتی پیوست شد (شناسه مدیا: {formData.media_id} {uploadedAudioName ? `- ${uploadedAudioName}` : ''})
                </span>
                <button
                  type="button"
                  onClick={() => {
                    handleChange('media_id', '');
                    setUploadedAudioName('');
                  }}
                  className="text-red hover:underline text-[11px]"
                >
                  حذف پیوست صوت
                </button>
              </div>
            )}
            {errors.media_id && <div className="text-xs text-red">{errors.media_id}</div>}
          </div>
        )}

        {/* بخش ویژه Reading: انتخاب یا ایجاد Passage */}
        {formData.section_name === 'Reading' && (
          <div className="p-3.5 rounded-xl border border-line bg-paper/60 space-y-3">
            <div className="text-xs font-bold text-navy">متن درک مطلب (Reading Passage)</div>
            <Select
              value={formData.passage_id}
              onChange={(e) => handleChange('passage_id', e.target.value)}
              disabled={isSaving}
            >
              <option value="">انتخاب متن درک مطلب از لیست...</option>
              {passages.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title}
                </option>
              ))}
            </Select>

            <div className="text-[11px] text-ink-soft">یا ایجاد متن جدید برای این سوال:</div>
            <Input
              value={newPassageTitle}
              onChange={(e) => setNewPassageTitle(e.target.value)}
              placeholder="عنوان متن (مثلاً: Climate of Kunduz)"
              disabled={isSaving}
            />
            <Textarea
              rows={3}
              value={newPassageText}
              onChange={(e) => setNewPassageText(e.target.value)}
              placeholder="متن کامل درک مطلب..."
              disabled={isSaving}
            />
            <Button
              type="button"
              size="sm"
              variant="outline"
              loading={creatingPassage}
              disabled={isSaving}
              onClick={handleCreatePassage}
            >
              + ثبت متن درک مطلب
            </Button>
            {errors.passage_id && <div className="text-xs text-red">{errors.passage_id}</div>}
          </div>
        )}

        <FieldRow cols={3}>
          <Field label="نوع سوال" required error={errors.question_type}>
            <Select
              value={formData.question_type}
              onChange={(e) => handleTypeChange(e.target.value)}
              disabled={isSaving}
            >
              <option value="single">چهارگزینه‌ای (تک‌پاسخی)</option>
              <option value="multi">چندگزینه‌ای (چندپاسخی)</option>
              <option value="true_false">صحیح / غلط (True/False)</option>
              <option value="short_answer">پاسخ کوتاه (Short Answer)</option>
            </Select>
          </Field>

          <Field label="سطح دشواری" required error={errors.difficulty}>
            <Select
              value={formData.difficulty}
              onChange={(e) => handleChange('difficulty', e.target.value)}
              disabled={isSaving}
            >
              <option value="easy">آسان (Easy)</option>
              <option value="medium">متوسط (Medium)</option>
              <option value="hard">سخت (Hard)</option>
            </Select>
          </Field>

          <Field label="نمره سوال" required error={errors.score}>
            <Input
              type="number"
              step="0.5"
              min="0.5"
              value={formData.score}
              onChange={(e) => handleChange('score', e.target.value)}
              disabled={isSaving}
            />
          </Field>
        </FieldRow>

        <Field label="موضوع سوال (Topic)" error={errors.topic}>
          <Input
            value={formData.topic}
            onChange={(e) => handleChange('topic', e.target.value)}
            placeholder="مثلاً: Present Perfect Tense یا Vocabulary"
            disabled={isSaving}
          />
        </Field>

        <Field label="متن سوال" required error={errors.question_text}>
          <Textarea
            rows={3}
            value={formData.question_text}
            onChange={(e) => handleChange('question_text', e.target.value)}
            placeholder="متن کامل سوال را وارد نمایید..."
            disabled={isSaving}
          />
        </Field>

        {/* فیلد اختصاصی پاسخ کوتاه (Short Answer) */}
        {formData.question_type === 'short_answer' && (
          <Field label="پاسخ نمونه / کلید پاسخ صحیح (اختیاری)" error={errors.short_answer_key}>
            <Input
              value={formData.short_answer_key}
              onChange={(e) => handleChange('short_answer_key', e.target.value)}
              placeholder="پاسخ کوتاه مورد انتظار یا کلید نمره‌دهی..."
              disabled={isSaving}
            />
          </Field>
        )}

        {/* گزینه‌های پاسخ برای سوالات گزینه‌ای و صحیح/غلط */}
        {formData.question_type !== 'short_answer' && (
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <label className="text-xs font-bold text-navy">
                {formData.question_type === 'true_false'
                  ? 'تعیین گزینه صحیح (پاسخ معتبر را علامت بزنید):'
                  : 'گزینه‌های پاسخ (گزینه صحیح را علامت بزنید):'}
              </label>
              {errors.options && <span className="text-xs text-red">{errors.options}</span>}
            </div>

            <div className="space-y-2">
              {formData.options.map((opt, idx) => {
                const optErr = errors[`options.${idx}.option_text`];
                return (
                  <div key={idx} className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-xs text-ink-soft w-4 text-center">
                        {String.fromCharCode(65 + idx)}
                      </span>
                      <div
                        className="flex items-center justify-center p-1.5 cursor-pointer"
                        onClick={() => !isSaving && handleSelectCorrect(idx)}
                        title="انتخاب به عنوان پاسخ صحیح"
                      >
                        <input
                          type={formData.question_type === 'multi' ? 'checkbox' : 'radio'}
                          name="correct_option"
                          checked={opt.is_correct}
                          onChange={() => handleSelectCorrect(idx)}
                          disabled={isSaving}
                          className="mt-0.5 cursor-pointer"
                        />
                      </div>
                      <Input
                        value={opt.option_text}
                        onChange={(e) => handleOptionTextChange(idx, e.target.value)}
                        placeholder={
                          formData.question_type === 'true_false'
                            ? idx === 0
                              ? 'صحیح (True)'
                              : 'غلط (False)'
                            : `متن گزینه ${String.fromCharCode(65 + idx)}...`
                        }
                        disabled={isSaving}
                        className="flex-1"
                      />
                      {/* دکمه حذف گزینه (فقط برای single و multi و وقتی بیش از ۲ گزینه داریم) */}
                      {formData.question_type !== 'true_false' && formData.options.length > 2 && (
                        <button
                          type="button"
                          onClick={() => handleRemoveOption(idx)}
                          disabled={isSaving}
                          className="text-xs text-ink-soft hover:text-red px-1.5 py-1 rounded transition-colors"
                          title="حذف این گزینه"
                        >
                          ✕
                        </button>
                      )}
                    </div>
                    {optErr && (
                      <div className="text-[11px] text-red mr-12">{optErr}</div>
                    )}
                  </div>
                );
              })}
            </div>

            {/* دکمه افزودن گزینه برای single و multi */}
            {formData.question_type !== 'true_false' && formData.options.length < 8 && (
              <div className="pt-1">
                <Button
                  type="button"
                  size="sm"
                  variant="ghost"
                  disabled={isSaving}
                  onClick={handleAddOption}
                  className="text-xs text-navy"
                >
                  + افزودن گزینه {String.fromCharCode(65 + formData.options.length)}
                </Button>
              </div>
            )}
          </div>
        )}

        <div className="flex items-center justify-end gap-2 pt-3 border-t border-line">
          <Button type="button" variant="ghost" onClick={onClose} disabled={isSaving}>
            انصراف
          </Button>
          <Button type="submit" variant="gold" loading={isSaving} disabled={isSaving}>
            {question ? 'ذخیره تغییرات' : 'ثبت در بانک سوالات'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
