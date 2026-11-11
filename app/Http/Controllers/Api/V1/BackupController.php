<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backup\IndexBackupRequest;
use App\Http\Resources\BackupResource;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * BackupController — مدیریت نسخه‌برداری کامل دستی از دیتابیس.
 * تمام عملیات فقط برای Super Admin مجاز است.
 * Restore از طریق این کنترلر انجام نمی‌شود — فقط Artisan Command (فاز بعدی).
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backupService)
    {
    }

    // ─── Index ────────────────────────────────────────────────
    public function index(IndexBackupRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Backup::class);

        $validated = $request->validated();

        $query = Backup::query()->with('initiator:id,name');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $query->orderByDesc('created_at');

        $perPage = $validated['per_page'] ?? 15;

        $backups = $query->paginate($perPage);

        return BackupResource::collection($backups)->response();
    }

    // ─── Store ────────────────────────────────────────────────
    /**
     * ایجاد نسخهٔ پشتیبان کامل جدید (full, manual).
     * ثبت در Audit Log فقط در صورت موفقیت.
     */
    public function store(): JsonResponse
    {
        Gate::authorize('create', Backup::class);

        try {
            $backup = $this->backupService->createFullBackup(auth('admin')->id());
        } catch (\RuntimeException $e) {
            // پیام واقعی Exception در پاسخ قرار نمی‌گیرد — جزئیات در رکورد Backup (failure_reason) ثبت شده است.
            return response()->json([
                'success' => false,
                'message' => 'ایجاد نسخهٔ پشتیبان با خطا مواجه شد. برای جزئیات، تاریخچهٔ نسخه‌های پشتیبان را بررسی کنید.',
            ], 500);
        }

        $backup->load('initiator:id,name');

        AuditLog::create([
            'user_id' => auth('admin')->id(),
            'role' => auth('admin')->user()->role->name ?? null,
            'action' => 'Backup:created',
            'module' => 'backups',
            'target_type' => 'Backup',
            'target_id' => $backup->id,
            'new_value' => [
                'status' => $backup->status,
                'backup_type' => $backup->backup_type,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return (new BackupResource($backup))->response()->setStatusCode(201);
    }

    // ─── Show ─────────────────────────────────────────────────
    public function show(Backup $backup): JsonResponse
    {
        Gate::authorize('view', $backup);

        $backup->load('initiator:id,name');

        return (new BackupResource($backup))->response();
    }

    // ─── Download ─────────────────────────────────────────────
    /**
     * دانلود فایل نسخهٔ پشتیبان.
     * فقط Super Admin + فقط status=completed + ضد Path Traversal.
     */
    public function download(Backup $backup)
    {
        Gate::authorize('download', $backup);

        if ($backup->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'فقط نسخه‌های پشتیبان کامل‌شده قابل دانلود هستند.',
            ], 422);
        }

        $disk = config('backup.disk');
        $directory = config('backup.directory');

        if (!$backup->file_path || !$this->isPathWithinBackupDirectory($disk, $directory, $backup->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'مسیر فایل پشتیبان نامعتبر است.',
            ], 404);
        }

        if (!Storage::disk($disk)->exists($backup->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'فایل پشتیبان یافت نشد.',
            ], 404);
        }

        AuditLog::create([
            'user_id' => auth('admin')->id(),
            'role' => auth('admin')->user()->role->name ?? null,
            'action' => 'Backup:downloaded',
            'module' => 'backups',
            'target_type' => 'Backup',
            'target_id' => $backup->id,
            'new_value' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return Storage::disk($disk)->download($backup->file_path, basename($backup->file_path));
    }

    // ─── Destroy ──────────────────────────────────────────────
    /**
     * حذف نسخهٔ پشتیبان (فایل و رکورد).
     * اگر حذف فایل شکست بخورد، رکورد دیتابیس حذف نمی‌شود — جلوگیری از فایل بدون ردیابی.
     */
    public function destroy(Backup $backup): JsonResponse
    {
        Gate::authorize('delete', $backup);

        $disk = config('backup.disk');
        $directory = config('backup.directory');

        $backupId = $backup->id;
        $oldValues = [
            'status' => $backup->status,
            'backup_type' => $backup->backup_type,
        ];

        if ($backup->file_path && $this->isPathWithinBackupDirectory($disk, $directory, $backup->file_path)) {
            if (Storage::disk($disk)->exists($backup->file_path)) {
                try {
                    Storage::disk($disk)->delete($backup->file_path);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'حذف فایل پشتیبان با خطا مواجه شد؛ رکورد حذف نشد.',
                    ], 500);
                }
            }
        }

        $backup->delete();

        AuditLog::create([
            'user_id' => auth('admin')->id(),
            'role' => auth('admin')->user()->role->name ?? null,
            'action' => 'Backup:deleted',
            'module' => 'backups',
            'target_type' => 'Backup',
            'target_id' => $backupId,
            'old_value' => $oldValues,
            'new_value' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'نسخهٔ پشتیبان با موفقیت حذف شد.',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────
    /**
     * بررسی اینکه مسیر فایل داخل پوشهٔ مجاز Backup باقی مانده — ضد Path Traversal.
     */
    private function isPathWithinBackupDirectory(string $disk, string $directory, string $relativePath): bool
    {
        $realBase = realpath(Storage::disk($disk)->path($directory));
        $realFile = realpath(Storage::disk($disk)->path($relativePath));

        if ($realBase === false || $realFile === false) {
            return false;
        }

        return str_starts_with($realFile, $realBase);
    }
}