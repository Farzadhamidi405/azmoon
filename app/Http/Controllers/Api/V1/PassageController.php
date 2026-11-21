<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Passage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * کنترلر متون خواندنی درک مطلب (Reading Passages).
 */
class PassageController extends Controller
{
    /**
     * لیست متن‌های درک مطلب با فیلتر موضوع.
     * GET /api/v1/admin/passages
     */
    public function index(Request $request): JsonResponse
    {
        $passages = Passage::with(['subject:id,name', 'createdBy:id,name'])
            ->when($request->subject_id, fn($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->search, fn($q) => $q->where('title', 'like', '%' . $request->search . '%'))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data'    => $passages,
        ]);
    }

    /**
     * ایجاد متن درک مطلب جدید.
     * POST /api/v1/admin/passages
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'],
            'title'      => ['required', 'string', 'max:255'],
            'body_text'  => ['required', 'string', 'min:20'],
        ]);

        // پاک‌سازی برای جلوگیری از تزریق تگ‌های مخرب XSS
        $validated['body_text'] = strip_tags($validated['body_text'], '<p><br><strong><em><u><ol><ul><li>');
        $validated['created_by'] = Auth::id() ?? 1;

        $passage = Passage::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'متن درک مطلب با موفقیت ایجاد شد.',
            'data'    => $passage->load('subject'),
        ], 201);
    }

    /**
     * نمایش جزئیات یک متن درک مطلب همراه با سوالات وابسته.
     * GET /api/v1/admin/passages/{passage}
     */
    public function show(Passage $passage): JsonResponse
    {
        $passage->load(['subject', 'createdBy', 'questions.options']);

        return response()->json([
            'success' => true,
            'data'    => $passage,
        ]);
    }

    /**
     * ویرایش متن درک مطلب.
     * PUT /api/v1/admin/passages/{passage}
     */
    public function update(Request $request, Passage $passage): JsonResponse
    {
        $validated = $request->validate([
            'title'     => ['sometimes', 'string', 'max:255'],
            'body_text' => ['sometimes', 'string', 'min:20'],
        ]);

        if (isset($validated['body_text'])) {
            $validated['body_text'] = strip_tags($validated['body_text'], '<p><br><strong><em><u><ol><ul><li>');
        }

        $passage->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'متن درک مطلب با موفقیت به‌روزرسانی شد.',
            'data'    => $passage->fresh(['subject']),
        ]);
    }

    /**
     * حذف متن درک مطلب (در صورتی که سوال وابسته‌ای نداشته باشد).
     * DELETE /api/v1/admin/passages/{passage}
     */
    public function destroy(Passage $passage): JsonResponse
    {
        if ($passage->questions()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'امکان حذف متن وجود ندارد زیرا سوالاتی به آن متصل هستند.',
            ], 422);
        }

        $passage->delete();

        return response()->json([
            'success' => true,
            'message' => 'متن درک مطلب با موفقیت حذف شد.',
        ]);
    }
}
