<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * کنترلر اعلان‌های سیستمی — فقط خواندن + علامت‌گذاری وضعیت خوانده‌شدن.
 * بدون store/update/destroy (immutable از طریق API).
 * Step 12.9.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationQueryService $queryService)
    {
    }

    /**
     * لیست اعلان‌های قابل‌مشاهدهٔ کاربر جاری (own + broadcast).
     */
    public function index(IndexNotificationRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Notification::class);

        $notifications = $this->queryService->paginate($request->user(), $request->validated());

        return NotificationResource::collection($notifications)
            ->additional(['success' => true])
            ->response();
    }

    /**
     * تعداد اعلان‌های خوانده‌نشدهٔ قابل‌مشاهدهٔ کاربر جاری.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notification::class);

        $count = $this->queryService->unreadCount($request->user());

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $count],
        ]);
    }

    /**
     * جزئیات یک اعلان.
     * 404 اگر اعلان متعلق به کاربر دیگری باشد (نه own و نه broadcast).
     */
    public function show(Request $request, Notification $notification): JsonResponse
    {
        Gate::authorize('view', $notification);

        if (! $notification->isVisibleTo($request->user())) {
            abort(404);
        }

        return (new NotificationResource($notification))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * علامت‌گذاری یک اعلان به‌عنوان خوانده‌شده.
     * - 404: اعلان متعلق به کاربر دیگر.
     * - 409: اعلان broadcast (notifiable_user_id = null) — قابل mark-read از طریق API نیست.
     * - idempotent: اگر از قبل خوانده‌شده باشد، بدون خطا 200 برمی‌گرداند.
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        Gate::authorize('markAsRead', $notification);

        if (! $notification->isVisibleTo($request->user())) {
            abort(404);
        }

        if ($notification->notifiable_user_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'اعلان‌های عمومی (broadcast) را نمی‌توان به‌صورت جداگانه علامت‌گذاری کرد.',
            ], 409);
        }

        if (! $notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        return (new NotificationResource($notification->fresh()))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * علامت‌گذاری همهٔ اعلان‌های اختصاصی خوانده‌نشدهٔ کاربر جاری.
     * اعلان‌های broadcast دست‌نخورده می‌مانند.
     */
    public function readAll(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Notification::class);

        $updated = $this->queryService->markAllOwnAsRead($request->user());

        return response()->json([
            'success' => true,
            'message' => 'اعلان‌های اختصاصی خوانده‌نشده علامت‌گذاری شدند.',
            'data' => ['updated_count' => $updated],
        ]);
    }
}