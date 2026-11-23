import { useCallback, useEffect, useRef, useState } from 'react';
import api, { API_BASE, unwrapItem } from '../lib/api.js';

export default function useExamAttempt(initialAttemptId = null) {
  const [attempt, setAttempt] = useState(null);
  const [questions, setQuestions] = useState([]);
  const [answers, setAnswers] = useState({});
  const [flags, setFlags] = useState({});
  const [currentIndex, setCurrentIndex] = useState(0);
  const [remainingSeconds, setRemainingSeconds] = useState(0);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  // وضعیت ذخیره‌سازی: 'idle' | 'saving' | 'saved' | 'failed'
  const [saveStatus, setSaveStatus] = useState('saved');
  const [saveError, setSaveError] = useState(null);

  // کش کلیدهای Idempotency برای جلوگیری از تولید کلید جدید در تلاش مجدد همان مقدار
  const pendingKeysRef = useRef({});
  const activeSavesRef = useRef({});

  // استخراج و مپ کردن پاسخ‌های ذخیره‌شده از سرور
  const mapServerAnswers = (rawAnswers) => {
    const map = {};
    if (!rawAnswers) return map;

    const list = Array.isArray(rawAnswers) ? rawAnswers : Object.values(rawAnswers);
    list.forEach((item) => {
      const qId = item.exam_snapshot_question_id || item.question_id;
      if (!qId) return;

      if (Array.isArray(item.selected_option_ids) && item.selected_option_ids.length > 0) {
        map[qId] = item.selected_option_ids[0];
      } else if (item.selected_option_id !== undefined && item.selected_option_id !== null) {
        map[qId] = item.selected_option_id;
      } else if (item.answer_text) {
        map[qId] = item.answer_text;
      } else if (item.answer) {
        map[qId] = item.answer;
      }
    });

    return map;
  };

  // Start or resume attempt
  const startAttempt = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.post(`${API_BASE}/attempts/start`);
      const data = unwrapItem(res.data);
      const attemptObj = data.attempt || data;
      setAttempt(attemptObj);

      const qList = data.questions || attemptObj.questions || [];
      setQuestions(qList);

      const savedAnswers = data.answers || attemptObj.answers || {};
      const initialMap = mapServerAnswers(savedAnswers);
      setAnswers(initialMap);

      const remSec =
        data.remaining_seconds ??
        attemptObj.remaining_seconds ??
        (data.duration_minutes ? data.duration_minutes * 60 : 3600);
      setRemainingSeconds(remSec);
      return data;
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  // تولید یا بازیابی کلید Idempotency برای هر جفت (سوال + مقدار)
  const getIdempotencyKey = (questionId, value) => {
    const cacheKey = `${questionId}::${value}`;
    if (!pendingKeysRef.current[cacheKey]) {
      const rnd = Math.random().toString(36).substring(2, 9);
      pendingKeysRef.current[cacheKey] = `idemp_${questionId}_${Date.now()}_${rnd}`;
    }
    return pendingKeysRef.current[cacheKey];
  };

  // ذخیره فوری پاسخ (Auto-Save)
  const saveAnswer = async (questionId, value) => {
    if (!questionId) return;

    // به‌روزرسانی آنی و روان رابط کاربری (Optimistic UI Update)
    setAnswers((prev) => ({ ...prev, [questionId]: value }));

    if (!attempt?.id) return;

    setSaveStatus('saving');
    setSaveError(null);

    const isNumeric = typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value));
    const idempotencyKey = getIdempotencyKey(questionId, value);

    const payload = {
      exam_snapshot_question_id: Number(questionId),
      idempotency_key: idempotencyKey,
      selected_option_ids: isNumeric ? [Number(value)] : null,
      answer_text: isNumeric ? null : String(value ?? ''),
      time_spent_seconds: 2,
      client_timestamp: new Date().toISOString(),
    };

    activeSavesRef.current[questionId] = idempotencyKey;

    try {
      await api.post(`${API_BASE}/attempts/${attempt.id}/answer`, payload);

      // اگر کاربر در حین درخواست تغییر دیگری نداده باشد، وضعیت را 'saved' می‌کنیم
      if (activeSavesRef.current[questionId] === idempotencyKey) {
        setSaveStatus('saved');
        const cacheKey = `${questionId}::${value}`;
        delete pendingKeysRef.current[cacheKey];
      }
    } catch (err) {
      setSaveStatus('failed');
      const msg = err.response?.data?.message || err.apiMessage || 'خطا در ذخیره‌سازی پاسخ.';
      setSaveError(msg);
      // کلید را برای retry نگه می‌داریم
    }
  };

  // Toggle review flag
  const toggleFlag = (questionId) => {
    setFlags((prev) => ({ ...prev, [questionId]: !prev[questionId] }));
  };

  // Submit attempt
  const submitAttempt = async () => {
    if (!attempt?.id) return;
    setSubmitting(true);
    try {
      const res = await api.post(`${API_BASE}/attempts/${attempt.id}/submit`);
      return res.data;
    } finally {
      setSubmitting(false);
    }
  };

  // Anti-cheat event recorder
  const recordAntiCheat = async (type, meta = {}) => {
    if (!attempt?.id) return;
    try {
      await api.post(`${API_BASE}/attempts/${attempt.id}/anti-cheat/event`, {
        event_type: type,
        meta,
      });
    } catch {
      // non-blocking
    }
  };

  // Countdown timer
  useEffect(() => {
    if (remainingSeconds <= 0 || loading || !attempt) return;

    const interval = setInterval(() => {
      setRemainingSeconds((prev) => {
        if (prev <= 1) {
          clearInterval(interval);
          submitAttempt();
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [remainingSeconds > 0, loading, attempt?.id]);

  // Periodic heartbeat
  useEffect(() => {
    if (!attempt?.id) return;

    const interval = setInterval(() => {
      api
        .post(`${API_BASE}/attempts/${attempt.id}/heartbeat`, {
          remaining_seconds: remainingSeconds,
        })
        .catch(() => {});
    }, 15000);

    return () => clearInterval(interval);
  }, [attempt?.id, remainingSeconds]);

  return {
    attempt,
    questions,
    answers,
    flags,
    currentIndex,
    currentQuestion: questions[currentIndex] || null,
    remainingSeconds,
    loading,
    submitting,
    error,
    saveStatus,
    saveError,
    setCurrentIndex,
    startAttempt,
    saveAnswer,
    toggleFlag,
    submitAttempt,
    recordAntiCheat,
  };
}
