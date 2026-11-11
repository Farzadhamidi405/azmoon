<?php

namespace App\Http\Requests\Lab;

use App\Models\Lab;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validation and authorization for creating a Lab (POST /labs).
 */
class StoreLabRequest extends FormRequest
{
    /**
     * Only users allowed by LabPolicy::create may create a lab.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Lab::class);
    }

    /**
     * Rules: name is required (max 100, not unique by decision), location is optional (max 255).
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
