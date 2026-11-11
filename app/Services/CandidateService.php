<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CandidateService — منطق تجاری مدیریت متقاضیان.
 * تمام عملیات حساس (ایجاد، تولید گروهی) از طریق این Service انجام می‌شود.
 * Controller فقط درخواست را دریافت و به این Service واگذار می‌کند.
 * بند 11 SRS و اصل OOP بخش 56.
 */
class CandidateService
{
    /**
     * ایجاد یک متقاضی جدید به‌صورت دستی.
     * یوزرنیم و رمز عبور موقت به‌صورت خودکار تولید می‌شوند.
     *
     * @param array $data شامل: name, national_id (optional), exam_id, shift_id, lab_id
     * @param string $prefix پیشوند کد متقاضی — مثلاً KND
     * @return Candidate
     */
    public function createCandidate(array $data, string $prefix = 'KND'): Candidate
    {
        return DB::transaction(function () use ($data, $prefix) {
            // تولید کد یکتا برای متقاضی
            $code = $this->generateCandidateCode($prefix);

            // تولید یوزرنیم از کد
            $username = strtolower($code);

            // تولید رمز موقت — 8 کاراکتر تصادفی
            $temporaryPassword = $this->generateTemporaryPassword();

            $candidate = Candidate::create([
                'candidate_code'       => $code,
                'name'                 => $data['name'],
                'national_id'          => $data['national_id'] ?? null,
                'username'             => $username,
                'password'             => $temporaryPassword,
                'status'               => 'absent',
                'must_reset_password'  => true,
                'credential_expires_at' => now()->addDays(30),
            ]);

            return $candidate;
        });
    }

    /**
     * تولید گروهی متقاضیان.
     * هر متقاضی کد، یوزرنیم، و رمز موقت منحصربه‌فرد دریافت می‌کند.
     * از Transaction استفاده می‌شود — اگر یکی شکست بخورد، همه rollback می‌شوند.
     *
     * @param int $count تعداد متقاضیان
     * @param string $prefix پیشوند کد متقاضی
     * @return array آرایه‌ای از متقاضیان ایجادشده با رمز موقت
     */
    public function bulkGenerate(int $count, string $prefix = 'KND'): array
    {
        return DB::transaction(function () use ($count, $prefix) {
            $created = [];

            for ($i = 0; $i < $count; $i++) {
                $code     = $this->generateCandidateCode($prefix);
                $username = strtolower($code);
                $password = $this->generateTemporaryPassword();

                $candidate = Candidate::create([
                    'candidate_code'        => $code,
                    'name'                  => 'Candidate ' . $code,
                    'username'              => $username,
                    'password'              => $password,
                    'status'                => 'absent',
                    'must_reset_password'   => true,
                    'credential_expires_at' => now()->addDays(30),
                ]);

                // رمز خام فقط برای نمایش یک‌بار به Admin برمی‌گردد
                // در دیتابیس همیشه Hash ذخیره می‌شود
                $created[] = [
                    'candidate'        => $candidate,
                    'plain_password'   => $password,
                ];
            }

            return $created;
        });
    }

    /**
     * Check-in متقاضی — تأیید هویت و ثبت ورود.
     * وضعیت متقاضی به present تغییر می‌کند.
     *
     * @param Candidate $candidate
     * @return Candidate
     */
    public function checkIn(Candidate $candidate): Candidate
    {
        // بررسی اینکه متقاضی قبلاً Check-in نشده باشد
        if ($candidate->checked_in_at !== null) {
            return $candidate;
        }

        $candidate->update([
            'checked_in_at' => now(),
            'status'        => 'present',
        ]);

        return $candidate->fresh();
    }

    /**
     * بازنشانی رمز عبور متقاضی توسط Admin.
     * رمز جدید موقت است و متقاضی باید در اولین ورود آن را تغییر دهد.
     *
     * @param Candidate $candidate
     * @return string رمز موقت خام — فقط یک‌بار نمایش داده می‌شود
     */
    public function resetPassword(Candidate $candidate): string
    {
        $temporaryPassword = $this->generateTemporaryPassword();

        $candidate->update([
            'password'              => $temporaryPassword,
            'must_reset_password'   => true,
            'password_reset_token'  => null,
            'credential_expires_at' => now()->addDays(7),
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ]);

        return $temporaryPassword;
    }

    /**
     * ثبت تلاش ناموفق ورود و قفل حساب در صورت تجاوز از حد مجاز.
     * حداکثر 5 تلاش — بند 35 SRS.
     *
     * @param Candidate $candidate
     * @param int $maxAttempts
     * @param int $lockMinutes
     */
    public function recordFailedLogin(
        Candidate $candidate,
        int $maxAttempts = 5,
        int $lockMinutes = 15
    ): void {
        $attempts = $candidate->failed_login_attempts + 1;

        $update = ['failed_login_attempts' => $attempts];

        // اگر از حد مجاز تجاوز شد، حساب قفل می‌شود
        if ($attempts >= $maxAttempts) {
            $update['locked_until'] = now()->addMinutes($lockMinutes);
        }

        $candidate->update($update);
    }

    /**
     * ثبت ورود موفق — reset کردن تلاش‌های ناموفق.
     *
     * @param Candidate $candidate
     */
    public function recordSuccessfulLogin(Candidate $candidate): void
    {
        $candidate->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
        ]);
    }

    // ─── Private Helpers ─────────────────────────────────────

    /**
     * تولید کد یکتا برای متقاضی.
     * فرمت: PREFIX + عدد سه‌رقمی — مثلاً KND001
     * اگر کد تکراری باشد، عدد بعدی امتحان می‌شود.
     */
    private function generateCandidateCode(string $prefix): string
    {
        // آخرین کد با همین prefix را پیدا می‌کنیم
        $last = Candidate::withTrashed()
            ->where('candidate_code', 'like', $prefix . '%')
            ->orderByDesc('candidate_code')
            ->value('candidate_code');

        if ($last === null) {
            $next = 1;
        } else {
            // عدد را از انتهای کد استخراج می‌کنیم
            $number = (int) substr($last, strlen($prefix));
            $next   = $number + 1;
        }

        // پد کردن با صفر — حداقل 3 رقم
        return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * تولید رمز عبور موقت تصادفی — 8 کاراکتر.
     * ترکیبی از حروف بزرگ و اعداد برای خوانایی.
     */
    private function generateTemporaryPassword(): string
    {
        return strtoupper(Str::random(4)) . rand(1000, 9999);
    }
}