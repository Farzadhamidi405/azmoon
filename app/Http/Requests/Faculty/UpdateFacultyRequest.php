<?php

namespace App\Http\Requests\Faculty;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation برای ویرایش دانشکده.
 */
class UpdateFacultyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('faculty'));
    }

    public function rules(): array
    {
        $facultyId = $this->route('faculty')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:faculties,code,' . $facultyId],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'این کد دانشکده قبلاً ثبت شده است.',
        ];
    }
}