<?php

namespace App\Http\Requests\ManualOverride;

use App\Models\Attempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation و Authorization برای ثبت Manual Override.
 * بند 27 SRS — reason اجباری است، بدون دلیل ثبت نمی‌شود.
 */
class StoreManualOverrideRequest extends FormRequest
{
    /**
     * فقط Super Admin و Exam Administrator مجازند.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', \App\Models\ManualOverride::class);
    }

    /**
     * قوانین اعتبارسنجی Override.
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in([
                    'extend_time',
                    'reset_session',
                    'transfer_session',
                    'force_submit',
                    'reopen_attempt',
                    'change_computer',
                    'mark_absent',
                    'void_attempt',
                ]),
            ],

            // reason اجباری است — بند 27 SRS
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],

            // old_value و new_value اختیاری هستند اما باید array باشند
            'old_value' => ['nullable', 'array'],
            'new_value' => ['nullable', 'array'],
        ];
    }

    /**
     * پیام‌های خطای فارسی.
     */
    public function messages(): array
    {
        return [
            'action.required' => 'نوع عملیات Override الزامی است.',
            'action.in'       => 'نوع عملیات Override معتبر نیست.',
            'reason.required' => 'دلیل Override الزامی است.',
            'reason.min'      => 'دلیل Override باید حداقل ۱۰ کاراکتر باشد.',
            'reason.max'      => 'دلیل Override نمی‌تواند بیشتر از ۱۰۰۰ کاراکتر باشد.',
            'old_value.array' => 'مقدار قبلی باید آرایه باشد.',
            'new_value.array' => 'مقدار جدید باید آرایه باشد.',
        ];
    }
}