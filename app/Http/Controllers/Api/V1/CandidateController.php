<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Http\Requests\Candidate\AssignCandidateRequest;
use App\Http\Requests\Candidate\BulkGenerateCandidateRequest;
use App\Http\Requests\Candidate\ImportCandidateRequest;
use App\Http\Requests\Candidate\StoreCandidateRequest;
use App\Http\Requests\Candidate\UpdateCandidateRequest;
use App\Models\Candidate;
use App\Models\CandidateAssignment;
use App\Models\AuditLog;
use App\Models\EntryCard;
use App\Services\CandidateImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CandidateController — مدیریت متقاضیان توسط Admin.
 * شامل: افزودن دستی، تولید گروهی، Import از Excel، تخصیص آزمون.
 * بند 11 SRS — Section 6.
 */
class CandidateController extends Controller
{
    public function __construct(
        protected CandidateImportService $importService,
    ) {}

    // ─── List ─────────────────────────────────────────────────

    /**
     * لیست همه متقاضیان با فیلتر و Pagination.
     */
    public function index(): JsonResponse
    {
        $candidates = Candidate::query()
            ->when(request('search'), fn($q, $s) =>
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('candidate_code', 'like', "%{$s}%")
                  ->orWhere('username', 'like', "%{$s}%")
            )
            ->when(request('status'), fn($q, $s) =>
                $q->where('status', $s)
            )
            ->orderBy('created_at', 'desc')
            ->paginate(request('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $candidates,
        ]);
    }

    // ─── Store (Manual Add) ───────────────────────────────────

    /**
     * افزودن دستی یک متقاضی جدید.
     * یوزرنیم و رمز عبور به‌صورت خودکار تولید می‌شود.
     */
    public function store(StoreCandidateRequest $request, AuditLogService $audit): JsonResponse
    {
        $code     = $this->generateCandidateCode();
        $username = strtolower($code);
        $password = strtoupper(Str::random(4)) . rand(1000, 9999);

        $candidate = DB::transaction(function () use ($request, $code, $username, $password, $audit) {
            $newCandidate = Candidate::create([
                'candidate_code'      => $code,
                'name'                => $request->name,
                'national_id'         => $request->national_id,
                'username'            => $username,
                'password'            => $password,
                'status'              => 'absent',
                'must_reset_password' => true,
            ]);

            // audit ایجاد متقاضی در همان تراکنش؛ رمز و کد ملی هرگز ذخیره نمی‌شوند.
            $audit->created('candidates', 'Candidate:created', $newCandidate);

            return $newCandidate;
        });

        return response()->json([
            'success' => true,
            'message' => 'متقاضی با موفقیت ایجاد شد.',
            'data'    => [
                'candidate'      => $candidate,
                'plain_password' => $password,
            ],
        ], 201);
    }

    // ─── Show ─────────────────────────────────────────────────

    /**
     * مشاهده جزئیات یک متقاضی.
     */
    public function show(Candidate $candidate): JsonResponse
    {
        $candidate->load(['assignments.exam', 'assignments.shift', 'assignments.lab']);

        return response()->json([
            'success' => true,
            'data'    => $candidate,
        ]);
    }

    // ─── Update ───────────────────────────────────────────────

    /**
     * ویرایش اطلاعات متقاضی.
     */
    public function update(UpdateCandidateRequest $request, Candidate $candidate, AuditLogService $audit): JsonResponse
    {
        // snapshot قبل از ویرایش + ثبت audit در همان تراکنش؛ شکست audit کل ویرایش را برمی‌گرداند.
        DB::transaction(function () use ($request, $candidate, $audit) {
            $before = $audit->snapshot($candidate);

            $candidate->update($request->validated());

            $audit->updated('candidates', 'Candidate:updated', $candidate, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات متقاضی به‌روزرسانی شد.',
            'data'    => $candidate->fresh(),
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────

    /**
     * حذف نرم (Soft Delete) متقاضی.
     * متقاضی با Attempt فعال قابل حذف نیست.
     */
    public function destroy(Candidate $candidate, AuditLogService $audit): JsonResponse
    {
        $hasActiveAttempt = $candidate->attempts()
            ->whereIn('status', ['in_progress', 'disconnected'])
            ->exists();

        if ($hasActiveAttempt) {
            return response()->json([
                'success' => false,
                'message' => 'این متقاضی در حال امتحان است و قابل حذف نیست.',
            ], 422);
        }

        // snapshot قبل از حذف + ثبت audit در همان تراکنش.
        DB::transaction(function () use ($candidate, $audit) {
            $before = $audit->snapshot($candidate);

            $candidate->delete();

            $audit->deleted('candidates', 'Candidate:deleted', $candidate, $before);
        });

        return response()->json([
            'success' => true,
            'message' => 'متقاضی با موفقیت حذف شد.',
        ]);
    }

    // ─── Bulk Generate ────────────────────────────────────────

    /**
     * تولید گروهی متقاضیان.
     * بند 11 SRS — Bulk Generate.
     */
    public function bulkGenerate(BulkGenerateCandidateRequest $request, AuditLogService $audit): JsonResponse
    {
        $prefix  = $request->prefix ?? 'KND';
        $count   = $request->count;
        $created = [];

        DB::transaction(function () use ($count, $prefix, &$created, $audit) {
            for ($i = 0; $i < $count; $i++) {
                $code     = $this->generateCandidateCode($prefix);
                $username = strtolower($code);
                $password = strtoupper(Str::random(4)) . rand(1000, 9999);

                $candidate = Candidate::create([
                    'candidate_code'      => $code,
                    'name'                => "Candidate {$code}",
                    'username'            => $username,
                    'password'            => $password,
                    'status'              => 'absent',
                    'must_reset_password' => true,
                ]);

                $created[] = [
                    'candidate'      => $candidate,
                    'plain_password' => $password,
                ];
            }

            // یک audit برای کل تولید گروهی؛ فقط تعداد، پیشوند و شناسه‌ها (هیچ رمزی ثبت نمی‌شود).
            $audit->record(
                'candidates',
                'Candidate:bulk_generated',
                'Candidate',
                null,
                null,
                [
                    'count' => (int) $count,
                    'prefix' => $prefix,
                    'candidate_ids' => array_map(fn ($row) => $row['candidate']->id, $created),
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => "{$count} متقاضی با موفقیت ایجاد شد.",
            'data'    => $created,
        ], 201);
    }

    // ─── Import from Excel ────────────────────────────────────

    /**
     * Import متقاضیان از فایل Excel.
     * مرحله اول (confirmed=false): فقط Validation و پیش‌نمایش.
     * مرحله دوم (confirmed=true): ذخیره نهایی بعد از تأیید کاربر.
     * بند 12 SRS — Excel Import.
     */
    public function import(ImportCandidateRequest $request): JsonResponse
    {
        // خواندن ردیف‌ها از فایل
        $rows = $this->readExcelFile($request->file('file'));

        // مرحله اول: فقط Validation — بدون ذخیره
        if (! $request->boolean('confirmed')) {
            $preview = $this->importService->validate($rows);

            return response()->json([
                'success' => true,
                'message' => 'پیش‌نمایش آماده است. برای تأیید، confirmed=true ارسال کنید.',
                'data'    => $preview,
            ]);
        }

        // مرحله دوم: Import نهایی فقط ردیف‌های معتبر
        $validation = $this->importService->validate($rows);
        $result     = $this->importService->import(
            $validation['valid'],
            $request->input('prefix', 'KND')
        );

        return response()->json([
            'success' => true,
            'message' => count($result) . ' متقاضی با موفقیت Import شد.',
            'data'    => [
                'imported'         => count($result),
                'skipped_invalid'  => $validation['summary']['invalid_count'],
                'skipped_duplicate'=> $validation['summary']['duplicate_count'],
                'candidates'       => $result,
            ],
        ], 201);
    }

    // ─── Assign ───────────────────────────────────────────────

    /**
     * تخصیص متقاضی به آزمون، شفت، لابراتوار.
     * بند 11 SRS — Assign Exam/Shift/Lab/Computer.
     */
    public function assign(AssignCandidateRequest $request, Candidate $candidate, AuditLogService $audit): JsonResponse
    {
        $assignment = DB::transaction(function () use ($request, $candidate, $audit) {
            // تخصیص قبلی (در صورت وجود) برای old_value خوانده می‌شود.
            $previous = CandidateAssignment::where('candidate_id', $candidate->id)
                ->where('exam_id', $request->exam_id)
                ->first();

            $result = CandidateAssignment::updateOrCreate(
                [
                    'candidate_id' => $candidate->id,
                    'exam_id'      => $request->exam_id,
                ],
                [
                    'shift_id'    => $request->shift_id,
                    'lab_id'      => $request->lab_id,
                    'computer_id' => $request->computer_id,
                ]
            );

            // فقط فیلدهایی که واقعاً تغییر کرده‌اند (یا در تخصیص اول مقدار گرفته‌اند) ثبت می‌شوند.
            $changed = [];
            foreach (['shift_id', 'lab_id', 'computer_id'] as $field) {
                if ($previous === null || (string) $previous->{$field} !== (string) $result->{$field}) {
                    $changed[] = $field;
                }
            }

            // audit تخصیص در همان تراکنش
            $audit->record(
                'candidates',
                'Candidate:assigned',
                'Candidate',
                $candidate->id,
                $previous === null ? null : [
                    'assignment_id' => $previous->id,
                    'shift_id' => $previous->shift_id,
                    'lab_id' => $previous->lab_id,
                    'computer_id' => $previous->computer_id,
                ],
                [
                    'assignment_id' => $result->id,
                    'exam_id' => $result->exam_id,
                    'shift_id' => $result->shift_id,
                    'lab_id' => $result->lab_id,
                    'computer_id' => $result->computer_id,
                    'was_update' => $previous !== null,
                ],
                $changed
            );

            return $result;
        });

        return response()->json([
            'success' => true,
            'message' => 'تخصیص با موفقیت انجام شد.',
            'data'    => $assignment->load(['exam', 'shift', 'lab', 'computer']),
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────

    /**
     * تولید کد یکتای متقاضی — مثلاً KND001.
     * آخرین کد موجود را پیدا می‌کند و یکی اضافه می‌کند.
     */
    private function generateCandidateCode(string $prefix = 'KND'): string
    {
        $last = Candidate::withTrashed()
            ->where('candidate_code', 'like', "{$prefix}%")
            ->orderByDesc('candidate_code')
            ->value('candidate_code');

        $number = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    /**
     * خواندن ردیف‌های فایل Excel یا CSV.
     * فعلاً CSV ساده را پشتیبانی می‌کند.
     * برای xlsx، نیاز به پکیج maatwebsite/excel است.
     */
    private function readExcelFile(\Illuminate\Http\UploadedFile $file): array
    {
        $rows      = [];
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            // خواندن CSV
            $handle = fopen($file->getRealPath(), 'r');
            $headers = fgetcsv($handle); // ردیف اول header است

            // نرمال‌سازی header — حذف فاصله و تبدیل به lowercase
            $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($headers)) {
                    $rows[] = array_combine($headers, $row);
                }
            }

            fclose($handle);
        }

        // برای xlsx و xls: نیاز به maatwebsite/excel
        // در فاز بعدی اضافه می‌شود
        if (in_array($extension, ['xlsx', 'xls'])) {
            // placeholder — فعلاً آرایه خالی برمی‌گردد
            $rows = [];
        }

        return $rows;
    }






        /**
     * Check-in a candidate — ثبت حضور و تأیید هویت متقاضی.
     * فقط متقاضیانی که هنوز check-in نشده‌اند قابل check-in هستند.
     */
    public function checkIn(Candidate $candidate): JsonResponse
    {
        // اگر قبلاً check-in شده، خطا برگردان
        if ($candidate->checked_in_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'این متقاضی قبلاً Check-in شده است.',
                'data'    => ['checked_in_at' => $candidate->checked_in_at],
            ], 422);
        }

        // ثبت زمان check-in و تغییر وضعیت به present
        $candidate->update([
            'checked_in_at' => now(),
            'status'        => 'present',
        ]);

        // ثبت در Audit Log
        AuditLog::create([
            'user_id'     => auth('admin')->id(),
            'role'        => auth('admin')->user()->role->name ?? null,
            'action'      => 'CheckedIn',
            'module'      => 'candidates',
            'target_type' => 'Candidate',
            'target_id'   => $candidate->id,
            'old_value'   => ['status' => 'absent', 'checked_in_at' => null],
            'new_value'   => ['status' => 'present', 'checked_in_at' => $candidate->checked_in_at],
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Check-in با موفقیت انجام شد.',
            'data'    => $candidate->fresh(),
        ]);
    }

    /**
     * Entry Card — تولید یا بازیابی کارت ورود با QR Token امن.
     * QR Token یک‌بارمصرف و دارای تاریخ انقضا است.
     */
    /**
 * Entry Card — تولید یا بازیابی کارت ورود با QR Token امن.
 * QR Token یک‌بارمصرف و دارای تاریخ انقضا است.
 * بند 14 SRS و ERD V2.
 */
public function entryCard(Candidate $candidate): JsonResponse
{
    // بررسی اینکه آیا assignment دارد
    $assignment = $candidate->assignments()->latest()->first();

    if (!$assignment) {
        return response()->json(
            [
                'success' => false,
                'message' => 'این متقاضی هنوز به هیچ آزمون/شفتی تخصیص نیافته است.',
            ],
            422
        );
    }

    // آخرین کارت ورود موجود را بررسی می‌کنیم
    // از entryCards() (HasMany) استفاده می‌کنیم — نه entryCard()
    $entryCard = $candidate->entryCards()->latest()->first();

    if (!$entryCard || $entryCard->qr_expires_at->isPast()) {
        // ساختن کارت جدید یا تجدید QR Token امن
        $entryCard = $candidate->entryCards()->updateOrCreate(
            ['candidate_id' => $candidate->id],
            [
                'qr_token'      => \Illuminate\Support\Str::random(64),
                'qr_expires_at' => now()->addDays(30),
                'printed_at'    => null,
            ]
        );
    }

    // بارگذاری اطلاعات assignment
    $assignment->load(['exam', 'shift', 'lab', 'computer']);

    return response()->json([
        'success' => true,
        'data'    => [
            'candidate' => [
                'id'             => $candidate->id,
                'name'           => $candidate->name,
                'candidate_code' => $candidate->candidate_code,
            ],
            'exam'         => $assignment->exam?->title,
            'shift'        => $assignment->shift?->name,
            'lab'          => $assignment->lab?->name,
            'computer'     => $assignment->computer?->computer_code,
            'qr_token'     => $entryCard->qr_token,
            'qr_expires_at' => $entryCard->qr_expires_at,
            'printed_at'   => $entryCard->printed_at,
        ],
    ]);
}







}