<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamSection;
use App\Models\ExamSnapshot;
use App\Models\ExamSnapshotQuestion;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * سرویس ساخت و نگهداری اسنپ‌شات آزمون (Exam Snapshot).
 * وظیفه: انجماد سوالات، گزینه‌ها، متن‌های درک مطلب و مدیا برای تضمین یکپارچگی آزمون.
 */
class ExamSnapshotService
{
    /**
     * بازیابی یا ساخت اسنپ‌شات منجمد آزمون (Idempotent).
     */
    public function findOrCreateSnapshot(Exam $exam): ExamSnapshot
    {
        $existing = ExamSnapshot::where('exam_id', $exam->id)
            ->latest('created_at')
            ->first();

        if ($existing && $existing->snapshotQuestions()->count() > 0) {
            return $existing;
        }

        return $this->createSnapshot($exam);
    }

    /**
     * ایجاد یک اسنپ‌شات جدید برای آزمون بر اساس بخش‌ها و بانک سوالات.
     */
    public function createSnapshot(Exam $exam): ExamSnapshot
    {
        return DB::transaction(function () use ($exam) {
            // دریافت آخرین شماره نسخه
            $lastVersion = ExamSnapshot::where('exam_id', $exam->id)->max('version_number') ?? 0;
            $newVersion = $lastVersion + 1;

            $snapshot = ExamSnapshot::create([
                'exam_id'        => $exam->id,
                'version_number' => $newVersion,
                'is_immutable'   => true,
                'snapshot_hash'  => null,
            ]);

            // لود بخش‌های آزمون
            $sections = $exam->sections()->orderBy('display_order')->get();

            // در صورتی که آزمون فاقد بخش ذخیره‌شده بود، یک بخش پیش‌فرض بساز
            if ($sections->isEmpty()) {
                $defaultSection = $exam->sections()->create([
                    'name'           => 'General',
                    'question_count' => 50,
                    'total_marks'    => (float) $exam->total_marks,
                    'display_order'  => 1,
                ]);
                $sections = collect([$defaultSection]);
            }

            $displayOrder = 1;
            $snapshotPayloadForHash = [];

            // اگر آزمون مستقیماً دارای سوالات انتخاب‌شده باشد، از همان سوالات استفاده کن
            $attachedQuestions = $exam->questions()->with(['options', 'passage', 'media'])->get();
            if ($attachedQuestions->isNotEmpty()) {
                $targetCount = $attachedQuestions->count();
                $scorePerQuestion = $targetCount > 0 ? round(((float) $exam->total_marks / $targetCount), 2) : 1.0;
                $defaultSec = $sections->first();

                foreach ($attachedQuestions as $q) {
                    $optionsList = $q->options->map(function ($opt, $idx) {
                        return [
                            'id'            => $opt->id,
                            'option_text'   => $opt->option_text,
                            'is_correct'    => (bool) $opt->is_correct,
                            'display_order' => $opt->display_order ?? ($idx + 1),
                        ];
                    })->values()->toArray();

                    if ((bool) $exam->randomize_options && count($optionsList) > 1) {
                        shuffle($optionsList);
                    }

                    $secId = $q->pivot->exam_section_id ?: ($sections->firstWhere('name', $q->section_name)?->id ?: $defaultSec->id);
                    $finalScore = $q->pivot->score ?: $scorePerQuestion;

                    ExamSnapshotQuestion::create([
                        'exam_snapshot_id'       => $snapshot->id,
                        'question_id'            => $q->id,
                        'question_version_id'    => $q->current_version_id,
                        'exam_section_id'        => $secId,
                        'display_order'          => $displayOrder++,
                        'score'                  => $finalScore,
                        'question_text_snapshot' => $q->question_text,
                        'question_type_snapshot' => $q->question_type,
                        'options_snapshot'       => $optionsList,
                        'passage_snapshot'       => $q->passage ? $q->passage->body_text : null,
                        'media_snapshot'         => $q->media ? [
                            'id'                     => $q->media->id,
                            'type'                   => $q->media->type,
                            'file_path'              => $q->media->file_path,
                            'duration_seconds'       => $q->media->duration_seconds,
                            'allow_replay'           => (bool) $q->media->allow_replay,
                            'max_replay_count'       => $q->media->max_replay_count,
                            'allow_pause'            => (bool) $q->media->allow_pause,
                            'allow_seek'             => (bool) $q->media->allow_seek,
                            'playback_speed_options' => $q->media->playback_speed_options,
                        ] : null,
                        'randomization_seed'     => Str::random(16),
                    ]);

                    $snapshotPayloadForHash[] = [
                        'q_id' => $q->id,
                        'text' => $q->question_text,
                    ];
                }

                $hash = hash('sha256', json_encode($snapshotPayloadForHash));
                $snapshot->update(['snapshot_hash' => $hash]);

                return $snapshot;
            }

            foreach ($sections as $section) {
                // بررسی تنظیمات استخر سوالات (Question Pool)
                $pool = $section->questionPool;
                $targetCount = $pool && $pool->pick_count > 0
                    ? (int) $pool->pick_count
                    : max(1, (int) $section->question_count);

                $scorePerQuestion = $targetCount > 0
                    ? round(((float) $section->total_marks / $targetCount), 2)
                    : 1.0;

                // کوئری سوالات مصوب/فعال مرتبط با مضمون و بخش
                $questionsQuery = Question::with(['options', 'passage', 'media'])
                    ->where('subject_id', $exam->subject_id)
                    ->where(function ($q) use ($section) {
                        $q->where('section_name', $section->name)
                          ->orWhereNull('section_name');
                    })
                    ->whereIn('status', ['approved', 'active']);

                // اگر سوالات با این بخش نیافتیم، هر سوال تاییدشده مضمون را بردار
                if ($questionsQuery->count() === 0) {
                    $questionsQuery = Question::with(['options', 'passage', 'media'])
                        ->where('subject_id', $exam->subject_id)
                        ->whereIn('status', ['approved', 'active', 'draft']);
                }

                $selectedQuestions = $questionsQuery
                    ->inRandomOrder()
                    ->limit($targetCount)
                    ->get();

                $randomizeOptions = $pool ? (bool) $pool->randomize_options : (bool) $exam->randomize_options;

                foreach ($selectedQuestions as $q) {
                    $optionsList = $q->options->map(function ($opt, $idx) {
                        return [
                            'id'            => $opt->id,
                            'option_text'   => $opt->option_text,
                            'is_correct'    => (bool) $opt->is_correct,
                            'display_order' => $opt->display_order ?? ($idx + 1),
                        ];
                    })->values()->toArray();

                    if ($randomizeOptions && count($optionsList) > 1) {
                        shuffle($optionsList);
                    }

                    $optionsSnapshot = $optionsList;

                    $passageSnapshot = $q->passage ? $q->passage->body_text : null;

                    $mediaSnapshot = $q->media ? [
                        'id'                     => $q->media->id,
                        'type'                   => $q->media->type,
                        'file_path'              => $q->media->file_path,
                        'duration_seconds'       => $q->media->duration_seconds,
                        'allow_replay'           => (bool) $q->media->allow_replay,
                        'max_replay_count'       => $q->media->max_replay_count,
                        'allow_pause'            => (bool) $q->media->allow_pause,
                        'allow_seek'             => (bool) $q->media->allow_seek,
                        'playback_speed_options' => $q->media->playback_speed_options,
                    ] : null;

                    ExamSnapshotQuestion::create([
                        'exam_snapshot_id'       => $snapshot->id,
                        'question_id'            => $q->id,
                        'question_version_id'    => $q->current_version_id,
                        'exam_section_id'        => $section->id,
                        'display_order'          => $displayOrder++,
                        'score'                  => $scorePerQuestion,
                        'question_text_snapshot' => $q->question_text,
                        'question_type_snapshot' => $q->question_type,
                        'options_snapshot'       => $optionsSnapshot,
                        'passage_snapshot'       => $passageSnapshot,
                        'media_snapshot'         => $mediaSnapshot,
                        'randomization_seed'     => Str::random(16),
                    ]);

                    $snapshotPayloadForHash[] = [
                        'q_id' => $q->id,
                        'text' => $q->question_text,
                    ];
                }
            }

            // تولید هش یکپارچگی اسنپ‌شات
            $hash = hash('sha256', json_encode($snapshotPayloadForHash));
            $snapshot->update(['snapshot_hash' => $hash]);

            return $snapshot;
        });
    }
}
