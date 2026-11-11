<?php

namespace App\Http\Requests\Faculty;

use App\Models\Faculty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation برای ایجاد دانشکده جدید.
 * بند 60.2 SRS.
 */
class StoreFacultyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Faculty::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:faculties,code'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'نام دانشکده الزامی است.',
            'code.required' => 'کد دانشکده الزامی است.',
            'code.unique'   => 'این کد دانشکده قبلاً ثبت شده است.',
        ];
    }
}