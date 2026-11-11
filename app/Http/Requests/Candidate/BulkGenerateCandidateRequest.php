<?php

namespace App\Http\Requests\Candidate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی تولید گروهی متقاضیان.
 * بند 11 SRS.
 */
class BulkGenerateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('candidates.create');
    }

    public function rules(): array
    {
        return [
            'count'  => ['required', 'integer', 'min:1', 'max:200'],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha'],
        ];
    }

    public function messages(): array
    {
        return [
            'count.required' => 'تعداد متقاضیان الزامی است.',
            'count.integer'  => 'تعداد باید عدد صحیح باشد.',
            'count.min'      => 'حداقل ۱ متقاضی باید تولید شود.',
            'count.max'      => 'حداکثر ۲۰۰ متقاضی در یک عملیات مجاز است.',
            'prefix.alpha'   => 'پیشوند فقط می‌تواند شامل حروف باشد.',
        ];
    }
}