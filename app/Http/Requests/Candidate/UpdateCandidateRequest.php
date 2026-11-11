<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی ویرایش اطلاعات یک متقاضی.
 * بند 11 SRS.
 */
class UpdateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('candidates.update');
    }

    public function rules(): array
    {
        // candidate از route parameter گرفته می‌شود
        $candidateId = $this->route('candidate')?->id
            ?? $this->route('candidate');

        return [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'national_id' => [
                'nullable',
                'string',
                'min:6',
                'max:50',
                // unique به‌جز خود این متقاضی
                "unique:candidates,national_id,{$candidateId}",
            ],
            'status' => [
                'sometimes',
                'string',
                'in:present,absent,late,in_progress,completed,disqualified,cancelled',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'نام کامل الزامی است.',
            'national_id.unique' => 'این کد ملی قبلاً در سیستم ثبت شده است.',
            'national_id.min'    => 'کد ملی باید حداقل ۶ کاراکتر باشد.',
            'status.in'          => 'وضعیت وارد‌شده معتبر نیست.',
        ];
    }
}