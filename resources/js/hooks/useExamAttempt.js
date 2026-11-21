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

  // Sync / Heartbeat ref
  const heartbeatTimer = useRef(null);

  // Start or resume attempt
  const startAttempt = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.post(`${API_BASE}/attempts/start`);
      const data = unwrapItem(res.data);
      setAttempt(data.attempt || data);
      
      const qList = data.questions || data.attempt?.questions || [];
      setQuestions(qList);

      const savedAnswers = data.answers || data.attempt?.answers || {};
      const initialMap = {};
      if (Array.isArray(savedAnswers)) {
        savedAnswers.forEach((a) => {
          initialMap[a.question_id] = a.selected_option || a.answer_text || a.answer;
        });
      } else if (typeof savedAnswers === 'object') {
        Object.assign(initialMap, savedAnswers);
      }
      setAnswers(initialMap);

      const remSec = data.remaining_seconds ?? data.attempt?.remaining_seconds ?? (data.duration_minutes ? data.duration_minutes * 60 : 3600);
      setRemainingSeconds(remSec);
      return data;
    } catch (err) {
      setError(err);
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  // Save single answer
  const saveAnswer = async (questionId, value) => {
    // optimistic update
    setAnswers((prev) => ({ ...prev, [questionId]: value }));

    if (!attempt?.id) return;
    try {
      await api.post(`${API_BASE}/attempts/${attempt.id}/answer`, {
        question_id: questionId,
        selected_option: value,
        answer: value,
      });
    } catch (e) {
      // ignore network glitch; will re-sync
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
      api.post(`${API_BASE}/attempts/${attempt.id}/heartbeat`, {
        remaining_seconds: remainingSeconds,
      }).catch(() => {});
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
    setCurrentIndex,
    startAttempt,
    saveAnswer,
    toggleFlag,
    submitAttempt,
    recordAntiCheat,
  };
}
