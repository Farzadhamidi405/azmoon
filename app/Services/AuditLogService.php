<?php

namespace App\Services;

use App\Models\AuditLog;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * سرویس مرکزی ثبت Audit برای عملیات CRUD پنل ادمین.
 *
 * قواعد:
 * - فقط از لایه Controller و داخل همان تراکنش عملیات اصلی صدا زده می‌شود؛
 *   اگر ثبت audit شکست بخورد، استثنا بالا می‌رود و کل تراکنش برگشت می‌خورد.
 * - مقادیر حساس به‌طور کامل حذف می‌شوند و فقط «نام فیلد» در changed_fields می‌ماند.
 * - جدول audit_logs ستون changed_fields ندارد؛ این فهرست داخل new_value['changed_fields'] ذخیره می‌شود.
 */
class AuditLogService
{
    /**
     * بخش‌های نام کلید که حساس‌اند (مقایسه زیررشته‌ای و بدون حساسیت به حروف).
     * این فهرست به $hidden مدل‌ها وابسته نیست.
     */
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'secret',
        'national_id',
        'qr_token',
        'remember_token',
        'session_token',
    ];

    /**
     * فیلدهایی که تغییرشان به‌تنهایی معنادار نیست و در changed_fields نمی‌آیند.
     */
    private const IGNORED_FIELDS = ['updated_at'];

    /**
     * تنها نقطه نوشتن در audit_logs؛ ورودی‌ها را پاک‌سازی و رکورد را ایجاد می‌کند.
     */
    public function record(
        string $module,
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        array $changedFields = []
    ): AuditLog {
        // کاربر ادمین فعلی (طبق قرارداد موجود پروژه)
        $user = auth('admin')->user();

        // حذف کامل مقادیر حساس از old/new
        $old = $oldValue === null ? null : $this->sanitize($oldValue);
        $new = $newValue === null ? null : $this->sanitize($newValue);

        // فقط نام فیلدهای تغییرکرده نگهداری می‌شود (حتی برای فیلدهای حساس)
        if ($changedFields !== []) {
            $new = $new ?? [];
            $new['changed_fields'] = array_values(array_map('strval', $changedFields));
        }

        return AuditLog::create([
            'user_id' => auth('admin')->id(),
            'role' => $user?->role?->name,
            'action' => $action,
            'module' => $module,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_value' => $old,
            'new_value' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * ثبت ایجاد رکورد؛ new_value کل snapshot پاک‌سازی‌شده است.
     */
    public function created(string $module, string $action, Model $model): AuditLog
    {
        return $this->record(
            $module,
            $action,
            class_basename($model),
            (int) $model->getKey(),
            null,
            $this->snapshot($model)
        );
    }

    /**
     * ثبت ویرایش؛ فقط فیلدهای واقعاً تغییرکرده در old/new می‌آیند.
     * $before باید پیش از update با snapshot() گرفته شده باشد.
     */
    public function updated(string $module, string $action, Model $model, array $before): AuditLog
    {
        $after = $this->snapshot($model);
        $changed = $this->changedFieldNames($before, $after);

        return $this->record(
            $module,
            $action,
            class_basename($model),
            (int) $model->getKey(),
            array_intersect_key($before, array_flip($changed)),
            array_intersect_key($after, array_flip($changed)),
            $changed
        );
    }

    /**
     * ثبت حذف؛ $before باید پیش از حذف گرفته شده باشد.
     */
    public function deleted(string $module, string $action, Model $model, array $before): AuditLog
    {
        return $this->record(
            $module,
            $action,
            class_basename($model),
            (int) $model->getKey(),
            $before,
            null
        );
    }

    /**
     * ثبت تغییر وضعیت؛ $extra فیلدهای جانبی (مثل published_at) را هم نگه می‌دارد.
     */
    public function statusChanged(
        string $module,
        string $action,
        Model $model,
        string $oldStatus,
        string $newStatus,
        array $extra = []
    ): AuditLog {
        return $this->record(
            $module,
            $action,
            class_basename($model),
            (int) $model->getKey(),
            ['status' => $oldStatus],
            array_merge(['status' => $newStatus], $extra),
            array_merge(['status'], array_keys($extra))
        );
    }

    /**
     * کپی ویژگی‌های مدل (با نمایش فیلدهای hidden)؛ پاک‌سازی بعداً در record() انجام می‌شود.
     */
    public function snapshot(Model $model): array
    {
        $copy = clone $model;
        $copy->makeVisible($copy->getHidden());

        return $copy->attributesToArray();
    }

    /**
     * پاک‌سازی بازگشتی: کلیدهای حساس حذف و تاریخ‌ها به رشته تبدیل می‌شوند.
     */
    public function sanitize(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif (is_array($value)) {
                $value = $this->sanitize($value);
            } elseif (is_object($value)) {
                $encoded = json_decode(json_encode($value), true);
                $value = is_array($encoded) ? $this->sanitize($encoded) : $encoded;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * آیا نام کلید حساس است؟ (زیررشته‌ای، بدون حساسیت به حروف)
     */
    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * نام فیلدهایی که بین before و after تفاوت دارند (به‌جز IGNORED_FIELDS).
     */
    private function changedFieldNames(array $before, array $after): array
    {
        $changed = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            if (json_encode($before[$field] ?? null) !== json_encode($after[$field] ?? null)) {
                $changed[] = (string) $field;
            }
        }

        return $changed;
    }
}
