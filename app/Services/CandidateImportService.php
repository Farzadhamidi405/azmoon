<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * CandidateImportService — Import متقاضیان از Excel/CSV.
 * قبل از Import، هر ردیف اعتبارسنجی می‌شود.
 * نتیجه: Valid، Duplicate، Invalid — با دلیل دقیق برای هر خطا.
 * تأیید کاربر قبل از Import نهایی الزامی است — بند 12 SRS.
 */
class CandidateImportService
{
    /**
     * اعتبارسنجی ردیف‌های Excel قبل از Import.
     * هیچ رکوردی در این مرحله ذخیره نمی‌شود.
     *
     * @param array $rows آرایه‌ای از ردیف‌ها — هر ردیف یک array است
     * @return array نتیجهٔ اعتبارسنجی: valid, duplicate, invalid, errors
     */
    public function validate(array $rows): array
    {
        $valid     = [];
        $duplicate = [];
        $invalid   = [];
        $errors    = [];

        // کدهای ملی که در همین فایل تکرار شده‌اند
        $seenNationalIds = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // ردیف اول header است

            // اعتبارسنجی فیلدهای اجباری
            $rowErrors = $this->validateRow($row, $rowNumber);

            if (!empty($rowErrors)) {
                $invalid[] = $row;
                $errors[]  = [
                    'row'    => $rowNumber,
                    'errors' => $rowErrors,
                ];
                continue;
            }

            $nationalId = trim($row['national_id'] ?? '');
            $name       = trim($row['name'] ?? '');

            // بررسی تکراری بودن در همین فایل
            if ($nationalId && in_array($nationalId, $seenNationalIds)) {
                $duplicate[] = $row;
                $errors[]    = [
                    'row'    => $rowNumber,
                    'errors' => ['national_id' => 'کد ملی در همین فایل تکرار شده است'],
                ];
                continue;
            }

            // بررسی تکراری بودن در دیتابیس
            if ($nationalId && Candidate::where('national_id', $nationalId)->exists()) {
                $duplicate[] = $row;
                $errors[]    = [
                    'row'    => $rowNumber,
                    'errors' => ['national_id' => 'کد ملی از قبل در سیستم ثبت شده است'],
                ];
                continue;
            }

            // ردیف معتبر است
            if ($nationalId) {
                $seenNationalIds[] = $nationalId;
            }

            $valid[] = $row;
        }

        return [
            'total'     => count($rows),
            'valid'     => $valid,
            'duplicate' => $duplicate,
            'invalid'   => $invalid,
            'errors'    => $errors,
            'summary'   => [
                'total_count'     => count($rows),
                'valid_count'     => count($valid),
                'duplicate_count' => count($duplicate),
                'invalid_count'   => count($invalid),
            ],
        ];
    }

    /**
     * Import نهایی ردیف‌های معتبر.
     * فقط بعد از تأیید کاربر فراخوانی می‌شود.
     * از Transaction استفاده می‌شود — همه یا هیچ.
     *
     * @param array $validRows ردیف‌های معتبر از validate()
     * @param string $prefix پیشوند کد متقاضی
     * @return array متقاضیان ایجادشده با رمز موقت
     */
    public function import(array $validRows, string $prefix = 'KND'): array
    {
        return DB::transaction(function () use ($validRows, $prefix) {
            $created = [];

            foreach ($validRows as $row) {
                $code     = $this->generateCandidateCode($prefix);
                $username = strtolower($code);
                $password = $this->generateTemporaryPassword();

                $candidate = Candidate::create([
                    'candidate_code'        => $code,
                    'name'                  => trim($row['name']),
                    'national_id'           => trim($row['national_id'] ?? '') ?: null,
                    'username'              => $username,
                    'password'              => $password,
                    'status'                => 'absent',
                    'must_reset_password'   => true,
                    'credential_expires_at' => now()->addDays(30),
                ]);

                // رمز خام فقط برای نمایش یک‌بار به Admin برمی‌گردد
                $created[] = [
                    'candidate'      => $candidate,
                    'plain_password' => $password,
                ];
            }

            return $created;
        });
    }

    // ─── Private Helpers ─────────────────────────────────────

    /**
     * اعتبارسنجی یک ردیف از Excel.
     * برمی‌گرداند آرایه‌ای از خطاها — اگر خالی باشد، ردیف معتبر است.
     */
    private function validateRow(array $row, int $rowNumber): array
    {
        $errors = [];

        // فیلد name اجباری است
        if (empty(trim($row['name'] ?? ''))) {
            $errors['name'] = "ردیف {$rowNumber}: فیلد نام کامل خالی است";
        }

        // اگر national_id وجود دارد، باید فرمت درستی داشته باشد
        $nationalId = trim($row['national_id'] ?? '');
        if ($nationalId && strlen($nationalId) < 6) {
            $errors['national_id'] = "ردیف {$rowNumber}: کد ملی باید حداقل 6 کاراکتر باشد";
        }

        return $errors;
    }

    /**
     * تولید کد یکتا برای متقاضی.
     * فرمت: PREFIX + عدد سه‌رقمی — مثلاً KND001
     */
    private function generateCandidateCode(string $prefix): string
    {
        $last = Candidate::withTrashed()
            ->where('candidate_code', 'like', $prefix . '%')
            ->orderByDesc('candidate_code')
            ->value('candidate_code');

        if ($last === null) {
            $next = 1;
        } else {
            $number = (int) substr($last, strlen($prefix));
            $next   = $number + 1;
        }

        return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * تولید رمز عبور موقت تصادفی — 8 کاراکتر.
     */
    private function generateTemporaryPassword(): string
    {
        return strtoupper(Str::random(4)) . rand(1000, 9999);
    }
}