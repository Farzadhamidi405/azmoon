<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * کنترلر فایل‌های صوتی و چندرسانه‌ای برای آزمون‌های Listening.
 */
class MediaController extends Controller
{
    /**
     * آپلود فایل صوتی جدید برای سوالات Listening.
     * POST /api/v1/admin/media
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'                  => ['required', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:20480'], // max 20MB
            'type'                  => ['nullable', 'in:audio,video'],
            'allow_replay'          => ['nullable', 'boolean'],
            'max_replay_count'      => ['nullable', 'integer', 'min:1', 'max:10'],
            'allow_pause'           => ['nullable', 'boolean'],
            'allow_seek'            => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $type = $request->input('type', 'audio');

        // ذخیره در دیسک خصوصی امن (local)
        $path = $file->store('media/' . $type, 'local');

        $media = Media::create([
            'type'                   => $type,
            'file_path'              => $path,
            'duration_seconds'       => $request->input('duration_seconds', null),
            'allow_replay'           => $request->boolean('allow_replay', false),
            'max_replay_count'       => $request->input('max_replay_count', 1),
            'allow_pause'            => $request->boolean('allow_pause', true),
            'allow_seek'             => $request->boolean('allow_seek', false),
            'playback_speed_options' => [1.0],
            'uploaded_by'            => Auth::id() ?? 1,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'فایل صوتی با موفقیت بارگذاری شد.',
            'data'    => $media,
        ], 201);
    }

    /**
     * استریم یا تحویل محافظت‌شده فایل صوتی برای داوطلب یا ادمین.
     * GET /api/v1/media/{media}/stream
     */
    public function stream(Media $media): BinaryFileResponse|JsonResponse
    {
        $adminUser = Auth::guard('admin')->user();
        $candidateUser = Auth::guard('candidate')->user();

        if (!$adminUser && !$candidateUser) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت نشده است.',
            ], 401);
        }

        // بررسی دسترسی اختصاصی داوطلب و قوانین آزمون جاری
        if ($candidateUser && !$adminUser) {
            $attempt = Attempt::where('candidate_id', $candidateUser->id)
                ->whereIn('status', ['in_progress', 'disconnected'])
                ->latest()
                ->first();

            if (!$attempt || !$attempt->exam_snapshot_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'هیچ آزمون فعالی برای شما یافت نشد.',
                ], 403);
            }

            // بررسی تعلق این مدیا به سوالات اسنپ‌شات منجمد آزمون داوطلب
            $snapshot = $attempt->examSnapshot;
            $hasMedia = $snapshot ? $snapshot->snapshotQuestions()
                ->where(function ($q) use ($media) {
                    $q->whereJsonContains('media_snapshot->id', (int) $media->id)
                      ->orWhereJsonContains('media_snapshot->id', (string) $media->id);
                })->exists() : false;

            if (!$hasMedia) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما به این فایل صوتی در این آزمون دسترسی ندارید.',
                ], 403);
            }

            // بررسی سقف تعداد دفعات پخش (Replay Limit) در Backend
            $cacheKey = "attempt_media_plays:{$attempt->id}:{$media->id}";
            $currentPlays = (int) cache()->get($cacheKey, 0);
            $maxReplays = $media->allow_replay ? max(1, (int) $media->max_replay_count) : 1;

            $rangeHeader = request()->header('Range');
            $isStartOfAudio = empty($rangeHeader) || str_starts_with(trim($rangeHeader), 'bytes=0-');

            if ($isStartOfAudio) {
                if ($currentPlays >= $maxReplays) {
                    return response()->json([
                        'success' => false,
                        'message' => 'سقف تعداد دفعات مجاز پخش این فایل صوتی به اتمام رسیده است.',
                    ], 403);
                }
                cache()->put($cacheKey, $currentPlays + 1, now()->addHours(4));
            }
        }

        if (!Storage::disk('local')->exists($media->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'فایل صوتی در سرور یافت نشد.',
            ], 404);
        }

        $fullPath = Storage::disk('local')->path($media->file_path);
        $mime = mime_content_type($fullPath) ?: 'audio/mpeg';

        return response()->file($fullPath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline',
            'Accept-Ranges'       => 'bytes',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
