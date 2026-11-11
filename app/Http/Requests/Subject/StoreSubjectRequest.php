<?php

namespace App\Http\Requests\Subject;

use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation برای ایجاد مضمون جدید.
 * بند 60.2 SRS — سیستم چندمضمونی/چنددانشکده‌ای.
 */
class StoreSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Subject::class);
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'code'       => ['required', 'string', 'max:50', 'unique:subjects,code'],
            'faculty_id' => ['nullable', 'integer', 'exists:faculties,id'],
            'is_active'  => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'       => 'نام مضمون الزامی است.',
            'code.required'       => 'کد مضمون الزامی است.',
            'code.unique'         => 'این کد مضمون قبلاً ثبت شده است.',
            'faculty_id.exists'   => 'دانشکده انتخاب‌شده معتبر نیست.',
        ];
    }
}