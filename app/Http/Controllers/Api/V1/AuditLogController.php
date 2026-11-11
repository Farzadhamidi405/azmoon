<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * API فقط‌خواندنی Audit Log (index/show).
 * عمداً store/update/destroy وجود ندارد: لاگ‌ها immutable هستند (SRS بند ۳۳).
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogQueryService $queryService)
    {
    }

    /** GET api/v1/admin/audit-logs — لیست با فیلتر و Pagination */
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $logs = $this->queryService->paginate($request->validated());

        return AuditLogResource::collection($logs)
            ->additional(['success' => true])
            ->response();
    }

    /** GET api/v1/admin/audit-logs/{auditLog} — جزئیات یک لاگ */
    public function show(AuditLog $auditLog): JsonResponse
    {
        Gate::authorize('view', $auditLog);

        $auditLog->load('user:id,name,username');

        return (new AuditLogResource($auditLog))
            ->additional(['success' => true])
            ->response();
    }
}