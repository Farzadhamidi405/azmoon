<?php

namespace App\Http\Requests\AuditLog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی فیلترهای لیست Audit Log.
 * Authorization در Controller (Policy) انجام می‌شود، نه اینجا.
 */
class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module'      => ['sometimes', 'string', 'max:100'],
            'action'      => ['sometimes', 'string', 'max:100'],
            'user_id'     => ['sometimes', 'integer', 'exists:users,id'],
            'target_type' => ['sometimes', 'string', 'max:100'],
            'target_id'   => ['sometimes', 'integer', 'min:1'],
            'ip_address'  => ['sometimes', 'ip'],
            'date_from'   => ['sometimes', 'date'],
            'date_to'     => ['sometimes', 'date', 'after_or_equal:date_from'],
            // سقف per_page برای جلوگیری از بارگذاری سنگین
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'        => ['sometimes', 'integer', 'min:1'],
        ];
    }
}