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
use App\Models\Exam;
use App\Models\Shift;
use App\Models\Lab;
use App\Models\Computer;
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
        $query = Candidate::query()
            ->with([
                'latestAssignment.exam:id,title',
                'latestAssignment.shift:id,name',
            ])
            ->when(request('search'), fn($q, $s) =>
                $q->where(function ($sub) use ($s) {
                    $sub->where('name', 'like', "%{$s}%")
                        ->orWhere('candidate_code', 'like', "%{$s}%")
                        ->orWhere('username', 'like', "%{$s}%");
                })
            )
            ->when(request('status'), fn($q, $s) =>
                $q->where('status', $s)
            )
            ->when(request('exam_id'), fn($q, $examId) =>
                $q->whereHas('assignments', fn($aq) => $aq->where('exam_id', $examId))
            );

        $stats = [
            'total'       => Candidate::count(),
            'present'     => Candidate::where('status', 'present')->count(),
            'absent'      => Candidate::where('status', 'absent')->count(),
            'in_progress' => Candidate::where('status', 'in_progress')->count(),
            'completed'   => Candidate::where('status', 'completed')->count(),
        ];

        $candidates = $query->orderBy('created_at', 'desc')
            ->paginate(request('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $candidates,
            'stats'   => $stats,
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
        $candidate->load([
            'assignments.exam:id,title',
            'assignments.shift:id,name',
            'assignments.lab:id,name',
            'assignments.computer:id,computer_code',
            'attempts.exam:id,title',
            'entryCards',
        ]);

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
     * Import متقاضیان از فایل Excel یا CSV.
     * مرحله اول (confirmed=false): فقط Validation ساختار، ستون‌ها و پیش‌نمایش بدون ذخیره در دیتابیس.
     * مرحله دوم (confirmed=true): ذخیره نهایی فقط برای ردیف‌های معتبر درون Database Transaction.
     * بند 12 SRS — Excel Import.
     */
    public function import(ImportCandidateRequest $request, AuditLogService $audit): JsonResponse
    {
        // خواندن و نرمال‌سازی ردیف‌ها از فایل اکسل یا CSV
        $rows = $this->readExcelFile($request->file('file'));

        if (empty($rows)) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ ردیف داده‌ای در فایل شناسایی نشد یا فایل فاقد ستون‌های نام / کد ملی است.',
            ], 422);
        }

        // مرحله اول: فقط بررسی ساختار و Validation — بدون هیچ ذخیره در پایگاه‌داده
        if (! $request->boolean('confirmed')) {
            $preview = $this->importService->validate($rows);

            return response()->json([
                'success' => true,
                'message' => 'پیش‌نمایش داده‌های فایل آماده است. برای ذخیره نهایی، درخواست confirmed=true ارسال نمایید.',
                'data'    => $preview,
            ]);
        }

        // مرحله دوم: Import نهایی فقط ردیف‌های معتبر
        $validation = $this->importService->validate($rows);
        $validRows  = $validation['valid'];

        if (empty($validRows)) {
            return response()->json([
                'success' => false,
                'message' => 'هیچ رکورد معتبری برای ذخیره در پایگاه‌داده یافت نشد.',
                'data'    => [
                    'imported_count'    => 0,
                    'skipped_invalid'   => $validation['summary']['invalid_count'],
                    'skipped_duplicate' => $validation['summary']['duplicate_count'],
                    'errors'            => $validation['errors'],
                ],
            ], 422);
        }

        $prefix = $request->input('prefix', 'KND');
        $result = $this->importService->import($validRows, $prefix);

        // ثبت audit برای فرآیند ایمپورت
        $audit->record(
            'candidates',
            'Candidate:imported',
            'Candidate',
            null,
            null,
            [
                'imported_count'    => count($result),
                'skipped_invalid'   => $validation['summary']['invalid_count'],
                'skipped_duplicate' => $validation['summary']['duplicate_count'],
                'candidate_ids'     => array_map(fn($r) => $r['candidate']->id, $result),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => count($result) . ' متقاضی معتبر با موفقیت در سیستم ثبت شدند.',
            'data'    => [
                'imported_count'    => count($result),
                'skipped_invalid'   => $validation['summary']['invalid_count'],
                'skipped_duplicate' => $validation['summary']['duplicate_count'],
                'candidates'        => $result,
            ],
        ], 201);
    }

    // ─── Assign ───────────────────────────────────────────────

    /**
     * تخصیص متقاضی به آزمون، شفت، لابراتوار و کامپیوتر.
     * بند 11 SRS — Assign Exam/Shift/Lab/Computer.
     * کلیه قوانین احراز صلاحیت، سقف ظرفیت، عدم تداخل کامپیوتر، آزمون‌های Published
     * و تراکنش اتمیک قفل‌دار در اینجا اعمال می‌گردد.
     */
    public function assign(AssignCandidateRequest $request, Candidate $candidate, AuditLogService $audit): JsonResponse
    {
        // 1. بررسی وضعیت خود متقاضی (نباید در حال آزمون یا تکمیل‌شده باشد)
        if (in_array($candidate->status, ['in_progress', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'امکان تغییر تخصیص برای متقاضی در حال برگزاری آزمون یا تکمیل‌شده وجود ندارد.',
            ], 422);
        }

        // 2. بررسی وضعیت آزمون — فقط published یا running
        $exam = Exam::find($request->exam_id);
        if (!$exam || !in_array($exam->status, ['published', 'running'])) {
            return response()->json([
                'success' => false,
                'message' => 'فقط آزمون‌های منتشرشده (Published) یا در حال برگزاری قابل تخصیص هستند.',
            ], 422);
        }

        // 3. بررسی تعلق شیفت به آزمون انتخاب‌شده
        $shift = Shift::find($request->shift_id);
        if (!$shift || (int) $shift->exam_id !== (int) $exam->id) {
            return response()->json([
                'success' => false,
                'message' => 'نوبت آزمون (شیفت) انتخاب‌شده متعلق به این آزمون نیست.',
            ], 422);
        }

        // 4. بررسی تطابق لابراتوار با محل برگزاری شیفت
        if ((int) $shift->lab_id !== (int) $request->lab_id) {
            return response()->json([
                'success' => false,
                'message' => 'لابراتوار انتخاب‌شده با محل تعیین‌شده برای این شیفت همخوانی ندارد.',
            ], 422);
        }

        // 5. بررسی تعلق کامپیوتر به لابراتوار انتخاب‌شده
        if ($request->filled('computer_id')) {
            $computer = Computer::find($request->computer_id);
            if (!$computer || (int) $computer->lab_id !== (int) $request->lab_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'کامپیوتر انتخاب‌شده متعلق به لابراتوار تعیین‌شده نیست.',
                ], 422);
            }
        }

        // 6. اجرای تخصیص درون Database Transaction با قفل سطری جهت جلوگیری از تداخل و همزمانی
        try {
            $assignment = DB::transaction(function () use ($request, $candidate, $exam, $shift, $audit) {
                // قفل ردیف شیفت
                $lockedShift = Shift::whereKey($shift->id)->lockForUpdate()->first();

                // بررسی سقف ظرفیت شیفت (با احتساب سایر متقاضیان)
                $currentAssignedCount = CandidateAssignment::where('shift_id', $lockedShift->id)
                    ->where('candidate_id', '!=', $candidate->id)
                    ->lockForUpdate()
                    ->count();

                if ($lockedShift->capacity !== null && $currentAssignedCount >= $lockedShift->capacity) {
                    throw new \RuntimeException("ظرفیت این شیفت ({$lockedShift->capacity} نفر) تکمیل شده است.");
                }

                // بررسی اشغال نبودن کامپیوتر در این شیفت توسط متقاضی دیگر
                if ($request->filled('computer_id')) {
                    $isOccupied = CandidateAssignment::where('shift_id', $lockedShift->id)
                        ->where('computer_id', $request->computer_id)
                        ->where('candidate_id', '!=', $candidate->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($isOccupied) {
                        throw new \RuntimeException('این کامپیوتر در شیفت انتخابی قبلاً به متقاضی دیگری تخصیص داده شده است.');
                    }
                }

                // دریافت تخصیص قبلی این متقاضی در همین آزمون برای ثبت ممیزی
                $previous = CandidateAssignment::where('candidate_id', $candidate->id)
                    ->where('exam_id', $exam->id)
                    ->lockForUpdate()
                    ->first();

                $result = CandidateAssignment::updateOrCreate(
                    [
                        'candidate_id' => $candidate->id,
                        'exam_id'      => $exam->id,
                    ],
                    [
                        'shift_id'    => $lockedShift->id,
                        'lab_id'      => $request->lab_id,
                        'computer_id' => $request->computer_id,
                    ]
                );

                // ثبت دقیق تغییرات در AuditLog
                $changed = [];
                foreach (['shift_id', 'lab_id', 'computer_id'] as $field) {
                    if ($previous === null || (string) $previous->{$field} !== (string) $result->{$field}) {
                        $changed[] = $field;
                    }
                }

                $audit->record(
                    'candidates',
                    'Candidate:assigned',
                    'Candidate',
                    $candidate->id,
                    $previous === null ? null : [
                        'assignment_id' => $previous->id,
                        'shift_id'      => $previous->shift_id,
                        'lab_id'        => $previous->lab_id,
                        'computer_id'   => $previous->computer_id,
                    ],
                    [
                        'assignment_id' => $result->id,
                        'exam_id'       => $result->exam_id,
                        'shift_id'      => $result->shift_id,
                        'lab_id'        => $result->lab_id,
                        'computer_id'   => $result->computer_id,
                        'was_update'    => $previous !== null,
                    ],
                    $changed
                );

                return $result;
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تخصیص آزمون به متقاضی با موفقیت انجام شد.',
            'data'    => $assignment->load(['exam', 'shift', 'lab', 'computer']),
        ]);
    }

    // ─── Private Helpers ──────────────────────────────────────

    /**
     * تولید کد یکتای متقاضی — مثلاً KND001.
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
     * نرمال‌سازی نام هدرهای ستون برای پشتیبانی از فرمت‌های فارسی و انگلیسی.
     */
    private function normalizeHeader(string $header): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', trim($header));
        $h = mb_strtolower($h, 'UTF-8');

        if (in_array($h, ['نام', 'نام کامل', 'نام و تخلص', 'اسم', 'اسم مکمل', 'name', 'fullname', 'full_name'])) {
            return 'name';
        }
        if (in_array($h, ['تذکره', 'نمبر تذکره', 'شماره تذکره', 'کد ملی', 'national_id', 'tazkira', 'nid'])) {
            return 'national_id';
        }
        return $h;
    }

    /**
     * خواندن ردیف‌های فایل Excel (XLSX/XLS) یا CSV.
     */
    private function readExcelFile(\Illuminate\Http\UploadedFile $file): array
    {
        $rows      = [];
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'csv') {
            $handle = fopen($file->getRealPath(), 'r');
            if ($handle !== false) {
                $rawHeaders = fgetcsv($handle, escape: "\\");
                if ($rawHeaders) {
                    $headers = array_map(fn($h) => $this->normalizeHeader((string)$h), $rawHeaders);
                    while (($row = fgetcsv($handle, escape: "\\")) !== false) {
                        if (count($row) === count($headers)) {
                            $combined = array_combine($headers, $row);
                            $name = trim($combined['name'] ?? '');
                            $nid  = trim($combined['national_id'] ?? '') ?: null;
                            if (!empty($name) || !empty($nid)) {
                                $rows[] = [
                                    'name'        => $name,
                                    'national_id' => $nid,
                                ];
                            }
                        }
                    }
                }
                fclose($handle);
            }
        } elseif (in_array($extension, ['xlsx', 'xls'])) {
            $rows = $this->parseXlsxFile($file->getRealPath());
        }

        return $rows;
    }

    /**
     * پارس خودکار و استاندارد فایل‌های XLSX بدون نیاز به پکیج‌های حجیم شخص ثالث.
     */
    private function parseXlsxFile(string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        // ۱. استخراج رشته‌های مشترک (sharedStrings)
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml) {
            $xml = @simplexml_load_string($sharedStringsXml);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $str = '';
                        foreach ($si->r as $r) {
                            $str .= (string) ($r->t ?? '');
                        }
                        $sharedStrings[] = $str;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // ۲. استخراج شیت اول (worksheets/sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (preg_match('#xl/worksheets/sheet\d+\.xml#', $entryName)) {
                    $sheetXml = $zip->getFromName($entryName);
                    break;
                }
            }
        }
        $zip->close();

        if (!$sheetXml) {
            return [];
        }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData->row)) {
            return [];
        }

        $rawRows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $val = '';
                $type = (string) $cell['t'];
                if ($type === 's') {
                    $idx = (int) $cell->v;
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $val = (string) $cell->is->t;
                } elseif (isset($cell->v)) {
                    $val = (string) $cell->v;
                }

                $cellRef = (string) $cell['r'];
                preg_match('/^([A-Z]+)/', $cellRef, $matches);
                $col = $matches[1] ?? '';
                if ($col) {
                    $rowData[$col] = trim($val);
                }
            }
            if (!empty($rowData)) {
                $rawRows[] = $rowData;
            }
        }

        if (empty($rawRows)) {
            return [];
        }

        // ردیف اول، هدر جدول است
        $headerRow = array_shift($rawRows);
        $headers = [];
        foreach ($headerRow as $col => $name) {
            $headers[$col] = $this->normalizeHeader((string) $name);
        }

        $result = [];
        foreach ($rawRows as $row) {
            $item = ['name' => '', 'national_id' => null];
            foreach ($headers as $col => $key) {
                if ($key === 'name' || $key === 'national_id') {
                    $val = trim($row[$col] ?? '');
                    if ($key === 'national_id' && $val === '') {
                        $val = null;
                    }
                    $item[$key] = $val;
                }
            }
            if (!empty($item['name']) || !empty($item['national_id'])) {
                $result[] = $item;
            }
        }

        return $result;
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

    /**
     * دریافت لیست کارت‌های ورود متقاضیان برای چاپ گروهی.
     */
    public function allEntryCards(): JsonResponse
    {
        $assignments = CandidateAssignment::with([
            'candidate',
            'exam:id,title',
            'shift:id,name,start_time',
            'lab:id,name',
            'computer:id,computer_code',
        ])
        ->whereNotNull('candidate_id')
        ->latest('id')
        ->limit(100)
        ->get();

        $data = $assignments->map(function ($a) {
            return [
                'id'       => 'card-' . $a->id,
                'name'     => $a->candidate?->name ?? 'متقاضی',
                'code'     => $a->candidate?->candidate_code ?? 'KND-' . $a->id,
                'lab'      => ($a->lab?->name ?? 'Lab') . '/' . ($a->computer?->computer_code ?? 'PC-01'),
                'time'     => $a->shift?->name ?? 'شیفت صبح',
                'exam'     => $a->exam?->title ?? 'امتحان زبان انگلیسی',
                'qr_token' => md5(($a->candidate?->candidate_code ?? 'KND') . ($a->id ?? 1)),
            ];
        });

        // اگر هنوز تخصیصی در دیتابیس ثبت نشده بود، از جدول candidates لیست متقاضیان را بگیریم
        if ($data->isEmpty()) {
            $candidates = Candidate::latest('id')->limit(50)->get();
            $data = $candidates->map(function ($c) {
                return [
                    'id'       => 'card-cand-' . $c->id,
                    'name'     => $c->name,
                    'code'     => $c->candidate_code,
                    'lab'      => 'Lab1/PC-01',
                    'time'     => 'شیفت آزمون',
                    'exam'     => 'امتحان عمومی پوهنتون کندز',
                    'qr_token' => md5($c->candidate_code),
                ];
            });
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}