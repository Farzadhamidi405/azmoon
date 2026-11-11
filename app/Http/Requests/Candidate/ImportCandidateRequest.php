<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی Import از Excel.
 * بند 12 SRS.
 */
class ImportCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('candidates.create');
    }

    public function rules(): array
    {
        return [
            'file'   => [
                'required',
                'file',
                // فقط فرمت‌های Excel و CSV مجاز هستند
                'mimes:xlsx,xls,csv',
                // حداکثر 5 مگابایت
                'max:5120',
            ],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'فایل Excel الزامی است.',
            'file.mimes'    => 'فقط فایل‌های xlsx، xls، و csv مجاز هستند.',
            'file.max'      => 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.',
            'prefix.alpha'  => 'پیشوند فقط می‌تواند شامل حروف باشد.',
        ];
    }
}