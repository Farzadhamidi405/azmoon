<?php

namespace App\Http\Requests\Lab;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation and authorization for updating a Lab (PUT /labs/{lab}).
 */
class UpdateLabRequest extends FormRequest
{
    /**
     * Only users allowed by LabPolicy::update on the bound lab may update it.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('lab'));
    }

    /**
     * Rules: partial update. Sent fields are validated; missing fields stay unchanged.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
