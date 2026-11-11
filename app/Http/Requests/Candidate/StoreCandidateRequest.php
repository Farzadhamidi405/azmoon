<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی ایجاد دستی یک متقاضی جدید.
 * بند 11 SRS.
 */
class StoreCandidateRequest extends FormRequest
{
    /**
     * فقط کاربران دارای دسترسی candidates.create مجازند.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('candidates.create');
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'min:6', 'max:50', 'unique:candidates,national_id'],
            'prefix'      => ['nullable', 'string', 'max:10', 'alpha'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'نام کامل الزامی است.',
            'national_id.unique'   => 'این کد ملی قبلاً در سیستم ثبت شده است.',
            'national_id.min'      => 'کد ملی باید حداقل ۶ کاراکتر باشد.',
            'prefix.alpha'         => 'پیشوند فقط می‌تواند شامل حروف باشد.',
        ];
    }
}