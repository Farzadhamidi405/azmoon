<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation برای ویرایش مضمون (شامل فعال/غیرفعال‌سازی).
 */
class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('subject'));
    }

    public function rules(): array
    {
        $subjectId = $this->route('subject')?->id;

        return [
            'name'       => ['sometimes', 'string', 'max:255'],
            'code'       => ['sometimes', 'string', 'max:50', 'unique:subjects,code,' . $subjectId],
            'faculty_id' => ['sometimes', 'nullable', 'integer', 'exists:faculties,id'],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'       => 'این کد مضمون قبلاً ثبت شده است.',
            'faculty_id.exists' => 'دانشکده انتخاب‌شده معتبر نیست.',
        ];
    }
}