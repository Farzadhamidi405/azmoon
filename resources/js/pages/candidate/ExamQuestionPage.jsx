import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Badge, Button, ConfirmDialog, Spinner } from '../../components/ui/index.js';
import useExamAttempt from '../../hooks/useExamAttempt.js';

export default function ExamQuestionPage() {
  const navigate = useNavigate();
  const {
    attempt,
    questions,
    answers,
    flags,
    currentIndex,
    currentQuestion,
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
  } = useExamAttempt();

  const [confirmSubmitOpen, setConfirmSubmitOpen] = useState(false);

  // Audio Playback tracker: map of questionId -> playCount
  const [audioPlays, setAudioPlays] = useState({});
  const audioRef = useRef(null);
  const MAX_AUDIO_PLAYS = 2;

  // Initialize attempt on mount
  useEffect(() => {
    startAttempt().catch(() => {});
  }, [startAttempt]);

  // Anti-cheat listeners (Honest browser-level monitoring)
  useEffect(() => {
    const handleVisibility = () => {
      if (document.hidden) {
        recordAntiCheat('tab_switch', { timestamp: new Date().toISOString() });
      }
    };

    const handleFullscreen = () => {
      if (!document.fullscreenElement) {
        recordAntiCheat('fullscreen_exit', { timestamp: new Date().toISOString() });
      }
    };

    const handleCopy = (e) => {
      e.preventDefault();
      recordAntiCheat('clipboard_attempt', { action: 'copy' });
    };

    window.addEventListener('visibilitychange', handleVisibility);
    document.addEventListener('fullscreenchange', handleFullscreen);
    window.addEventListener('copy', handleCopy);

    return () => {
      window.removeEventListener('visibilitychange', handleVisibility);
      document.removeEventListener('fullscreenchange', handleFullscreen);
      window.removeEventListener('copy', handleCopy);
    };
  }, [recordAntiCheat]);

  const handleFinalSubmit = async () => {
    try {
      await submitAttempt();
      navigate('/candidate/done');
    } catch {
      navigate('/candidate/done');
    }
  };

  // Audio player with playback limit enforcement
  const handlePlayAudio = (qId) => {
    const currentPlays = audioPlays[qId] || 0;
    if (currentPlays >= MAX_AUDIO_PLAYS) return;

    if (audioRef.current) {
      audioRef.current.play();
      setAudioPlays((prev) => ({ ...prev, [qId]: currentPlays + 1 }));
    }
  };

  // Format timer HH:MM:SS
  const formatTimer = (totalSeconds) => {
    const s = Math.max(0, totalSeconds);
    const hrs = Math.floor(s / 3600);
    const mins = Math.floor((s % 3600) / 60);
    const secs = s % 60;
    if (hrs > 0) {
      return `${hrs.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  };

  const isLowTime = remainingSeconds <= 300;

  if (loading) {
    return (
      <div className="min-h-screen bg-canvas flex items-center justify-center font-vazir text-ink">
        <div className="text-center space-y-3">
          <Spinner size="lg" />
          <div className="font-bold text-navy">در حال بارگذاری و بازیابی سوالات آزمون...</div>
          <div className="text-xs text-ink-soft">لطفاً شکیبا باشید و پنجره را نبندید.</div>
        </div>
      </div>
    );
  }

  if (error && !questions.length) {
    return (
      <div className="min-h-screen bg-canvas flex items-center justify-center font-vazir p-4">
        <div className="card max-w-md p-6 text-center space-y-4">
          <div className="text-4xl text-red">⚠️</div>
          <div className="font-bold text-navy text-lg">خطا در بازیابی نشست آزمون</div>
          <div className="text-xs text-ink-soft leading-relaxed">
            {error.response?.data?.message || 'شما هنوز در شیفت جاری آزمون ثبت‌نام نشده‌اید یا آزمون قبلاً به پایان رسیده است.'}
          </div>
          <Button variant="primary" onClick={() => navigate('/candidate/instructions')}>
            بازگشت به راهنما
          </Button>
        </div>
      </div>
    );
  }

  const answeredCount = Object.keys(answers).length;
  const totalCount = questions.length;
  const currentPlays = currentQuestion ? audioPlays[currentQuestion.id] || 0 : 0;
  const audioRemainingPlays = Math.max(0, MAX_AUDIO_PLAYS - currentPlays);

  return (
    <div dir="rtl" className="min-h-screen bg-canvas font-vazir flex flex-col select-none">
      {/* Top Navbar */}
      <header className="h-16 bg-navy text-panel px-4 md:px-8 flex items-center justify-between shadow-md shrink-0">
        <div className="flex items-center gap-3">
          <div className="size-9 rounded-lg bg-gold/20 flex items-center justify-center font-black text-gold text-lg">
            ق
          </div>
          <div>
            <div className="font-bold text-sm">{attempt?.exam?.title || 'آزمون جاری پوهنتون کندز'}</div>
            <div className="text-[11px] text-panel/70">
              متقاضی: {attempt?.candidate?.name || 'داوطلب'}
            </div>
          </div>
        </div>

        <div className="flex items-center gap-4">
          {/* Countdown Clock */}
          <div
            className={`px-3.5 py-1.5 rounded-xl font-mono text-base md:text-lg font-black tracking-wider border ${
              isLowTime
                ? 'bg-red text-panel border-red animate-pulse'
                : 'bg-panel/10 text-gold border-gold/30'
            }`}
          >
            ⏱️ {formatTimer(remainingSeconds)}
          </div>

          <Button
            variant="teal"
            size="sm"
            onClick={() => setConfirmSubmitOpen(true)}
          >
            تحویل نهایی آزمون
          </Button>
        </div>
      </header>

      {/* Main Content Layout */}
      <div className="flex-1 flex flex-col md:flex-row overflow-hidden">
        {/* Question Panel */}
        <main className="flex-1 p-4 md:p-8 overflow-y-auto space-y-6">
          {currentQuestion ? (
            <div className="card p-6 md:p-8 space-y-6 max-w-4xl mx-auto shadow-sm">
              {/* Question Header */}
              <div className="flex items-center justify-between pb-4 border-b border-line">
                <div className="flex items-center gap-2">
                  <span className="font-black text-navy text-lg">
                    سوال {currentIndex + 1} از {totalCount}
                  </span>
                  <Badge tone="off">{currentQuestion.points ?? 1} نمره</Badge>
                </div>

                <Button
                  size="sm"
                  variant={flags[currentQuestion.id] ? 'gold' : 'outline'}
                  onClick={() => toggleFlag(currentQuestion.id)}
                >
                  🚩 {flags[currentQuestion.id] ? 'نشان‌دار شده' : 'نشان کردن برای مرور'}
                </Button>
              </div>

              {/* Question Body */}
              <div className="text-base md:text-lg font-medium text-ink leading-relaxed">
                {currentQuestion.question_text || currentQuestion.question_text_snapshot}
              </div>

              {/* Reading Passage if available */}
              {currentQuestion.passage_snapshot && (
                <div className="p-4 bg-paper rounded-xl border border-line text-sm text-ink leading-relaxed max-h-60 overflow-y-auto">
                  <div className="font-bold text-navy mb-1">متن درک مطلب (Reading Passage):</div>
                  {currentQuestion.passage_snapshot}
                </div>
              )}

              {/* Audio Player with Play Limit */}
              {currentQuestion.media_snapshot && (
                <div className="p-4 bg-paper rounded-xl border border-line space-y-2">
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-bold text-navy">فایل صوتی سوال شنیداری (Listening Audio):</span>
                    <span className="text-ink-soft">
                      تعداد دفعات پخش باقیمانده: <b className="text-navy">{audioRemainingPlays}</b> از {MAX_AUDIO_PLAYS}
                    </span>
                  </div>

                  <audio
                    ref={audioRef}
                    src={currentQuestion.media_snapshot}
                    onEnded={() => {}}
                    className="w-full"
                    controlsList="nodownload"
                  />

                  <div className="flex items-center gap-2 pt-1">
                    <Button
                      size="sm"
                      variant={audioRemainingPlays > 0 ? 'gold' : 'outline'}
                      disabled={audioRemainingPlays <= 0}
                      onClick={() => handlePlayAudio(currentQuestion.id)}
                    >
                      ▶ پخش فایل صوتی
                    </Button>
                    {audioRemainingPlays <= 0 && (
                      <span className="text-xs text-red">سقف پخش این فایل صوتی به اتمام رسیده است.</span>
                    )}
                  </div>
                </div>
              )}

              {/* Options */}
              <div className="space-y-3 pt-2">
                {currentQuestion.options_snapshot && currentQuestion.options_snapshot.length > 0 ? (
                  currentQuestion.options_snapshot.map((opt, idx) => {
                    const optKey = opt.key || opt.option_key || String.fromCharCode(65 + idx);
                    const isSelected = answers[currentQuestion.id] === optKey;
                    return (
                      <div
                        key={opt.id || idx}
                        onClick={() => saveAnswer(currentQuestion.id, optKey)}
                        className={`p-4 rounded-xl border transition-all cursor-pointer flex items-center gap-3 ${
                          isSelected
                            ? 'border-navy bg-navy/5 font-bold shadow-xs'
                            : 'border-line hover:border-navy/40 hover:bg-paper'
                        }`}
                      >
                        <div
                          className={`size-6 rounded-full border flex items-center justify-center text-xs font-mono font-bold ${
                            isSelected
                              ? 'bg-navy border-navy text-panel'
                              : 'border-line text-ink-soft'
                          }`}
                        >
                          {optKey}
                        </div>
                        <div className="text-sm md:text-base text-ink flex-1">
                          {opt.text || opt.option_text}
                        </div>
                      </div>
                    );
                  })
                ) : (
                  <div className="space-y-2">
                    <label className="text-xs text-ink-soft">پاسخ خود را در این بخش بنویسید:</label>
                    <textarea
                      rows={4}
                      className="w-full p-3 border border-line rounded-xl focus:border-navy focus:ring-1 focus:ring-navy text-sm font-vazir"
                      placeholder="متن پاسخ تشریحی..."
                      value={answers[currentQuestion.id] || ''}
                      onChange={(e) => saveAnswer(currentQuestion.id, e.target.value)}
                    />
                  </div>
                )}
              </div>

              {/* Navigation Controls */}
              <div className="flex justify-between items-center pt-6 border-t border-line">
                <Button
                  variant="outline"
                  disabled={currentIndex === 0}
                  onClick={() => setCurrentIndex((p) => p - 1)}
                >
                  ← سوال قبلی
                </Button>

                <div className="text-xs text-ink-soft">
                  پاسخ شما بلافاصله ذخیره می‌شود.
                </div>

                {currentIndex < totalCount - 1 ? (
                  <Button
                    variant="primary"
                    onClick={() => setCurrentIndex((p) => p + 1)}
                  >
                    سوال بعدی →
                  </Button>
                ) : (
                  <Button
                    variant="teal"
                    onClick={() => setConfirmSubmitOpen(true)}
                  >
                    تحویل آزمون
                  </Button>
                )}
              </div>
            </div>
          ) : (
            <div className="text-center py-12 text-ink-soft">هیچ سوالی یافت نشد.</div>
          )}
        </main>

        {/* Sidebar Question Palette */}
        <aside className="w-full md:w-72 bg-panel border-r border-line p-4 md:p-6 shrink-0 flex flex-col justify-between">
          <div>
            <div className="font-black text-navy text-sm mb-3">پالت سوالات آزمون</div>

            <div className="grid grid-cols-5 gap-2 max-h-[360px] overflow-y-auto p-1">
              {questions.map((q, idx) => {
                const isCurrent = idx === currentIndex;
                const isAnswered = answers[q.id] !== undefined && answers[q.id] !== '';
                const isFlagged = flags[q.id];

                let bgClass = 'bg-paper text-ink border-line';
                if (isAnswered) bgClass = 'bg-navy text-panel border-navy';
                if (isFlagged) bgClass = 'bg-gold text-panel border-gold';
                if (isCurrent) bgClass += ' ring-2 ring-teal ring-offset-2';

                return (
                  <button
                    key={q.id || idx}
                    type="button"
                    onClick={() => setCurrentIndex(idx)}
                    className={`size-10 rounded-lg border flex items-center justify-center font-bold text-xs transition-all ${bgClass}`}
                  >
                    {idx + 1}
                  </button>
                );
              })}
            </div>

            {/* Legend */}
            <div className="mt-6 space-y-2 text-xs text-ink-soft border-t border-line pt-4">
              <div className="flex items-center gap-2">
                <span className="size-3.5 rounded bg-navy shrink-0" />
                <span>پاسخ داده شده ({answeredCount})</span>
              </div>
              <div className="flex items-center gap-2">
                <span className="size-3.5 rounded bg-paper border border-line shrink-0" />
                <span>پاسخ داده نشده ({totalCount - answeredCount})</span>
              </div>
              <div className="flex items-center gap-2">
                <span className="size-3.5 rounded bg-gold shrink-0" />
                <span>نشان‌دار برای مرور ({Object.values(flags).filter(Boolean).length})</span>
              </div>
            </div>
          </div>

          <div className="pt-4 border-t border-line">
            <Button
              variant="outline"
              className="w-full text-xs"
              onClick={() => setConfirmSubmitOpen(true)}
            >
              اتمام و تحویل آزمون
            </Button>
          </div>
        </aside>
      </div>

      {/* Confirm Submission Dialog */}
      <ConfirmDialog
        open={confirmSubmitOpen}
        onClose={() => setConfirmSubmitOpen(false)}
        onConfirm={handleFinalSubmit}
        title="تأیید تحویل نهایی آزمون"
        message={`شما به ${answeredCount} سوال از مجموع ${totalCount} سوال پاسخ داده‌اید. آیا اطمینان دارید که می‌خواهید آزمون را تحویل داده و خاتمه دهید؟`}
        confirmLabel="بله، تحویل و ثبت نهایی"
        confirmTone="primary"
        busy={submitting}
      />
    </div>
  );
}
