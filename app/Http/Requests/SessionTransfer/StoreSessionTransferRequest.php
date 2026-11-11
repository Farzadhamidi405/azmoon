<?php

namespace App\Http\Requests\SessionTransfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation و Authorization برای ثبت Session Transfer.
 * بند 22 SRS — reason اجباری است.
 * کامپیوتر مقصد باید online و متفاوت از مبدأ باشد.
 */
class StoreSessionTransferRequest extends FormRequest
{
    /**
     * فقط Super Admin، Exam Administrator و Lab Manager مجازند.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', \App\Models\SessionTransfer::class);
    }

    /**
     * قوانین اعتبارسنجی Session Transfer.
     */
    public function rules(): array
    {
        return [
            // کامپیوتر مقصد — باید موجود و online باشد
            'to_computer_id' => [
                'required',
                'integer',
                'exists:computers,id',
            ],

            // reason اجباری است — بند 22 SRS
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:1000',
            ],
        ];
    }

    /**
     * پیام‌های خطای فارسی.
     */
    public function messages(): array
    {
        return [
            'to_computer_id.required' => 'کامپیوتر مقصد الزامی است.',
            'to_computer_id.exists'   => 'کامپیوتر مقصد یافت نشد.',
            'reason.required'         => 'دلیل انتقال الزامی است.',
            'reason.min'              => 'دلیل انتقال باید حداقل ۵ کاراکتر باشد.',
            'reason.max'              => 'دلیل انتقال نمی‌تواند بیشتر از ۱۰۰۰ کاراکتر باشد.',
        ];
    }
}