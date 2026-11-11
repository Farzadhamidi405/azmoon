<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'key' => $this->route('key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'key'   => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)*$/'],
            'value' => ['present', 'nullable', 'string'],
        ];
    }
}
