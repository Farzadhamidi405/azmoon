<?php

namespace App\Http\Requests\Incident;

use Illuminate\Foundation\Http\FormRequest;

/**
 * اعتبارسنجی ویرایش حادثه.
 * فقط حوادث با وضعیت open قابل ویرایش هستند — Policy این را کنترل می‌کند.
 * بند 26 SRS.
 */
class UpdateIncidentRequest extends FormRequest
{
    /**
     * Policy کنترل می‌کند که آیا حادثه open است و کاربر مجاز است.
     */
    public function authorize(): bool
    {
        $incident = $this->route('incident');
        return $this->user()->can('update', $incident);
    }

    public function rules(): array
    {
        return [
            'problem_description' => ['sometimes', 'string', 'min:10', 'max:2000'],
            'action_taken'        => ['sometimes', 'nullable', 'string', 'max:2000'],
            'responsible_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'candidate_id'        => ['sometimes', 'nullable', 'integer', 'exists:candidates,id'],
            'computer_id'         => ['sometimes', 'nullable', 'integer', 'exists:computers,id'],
            'attempt_id'          => ['sometimes', 'nullable', 'integer', 'exists:attempts,id'],
            'occurred_at'         => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'problem_description.min'    => 'توضیح مشکل باید حداقل ۱۰ کاراکتر باشد.',
            'responsible_user_id.exists' => 'کاربر مسئول در سیستم وجود ندارد.',
            'candidate_id.exists'        => 'متقاضی انتخاب‌شده در سیستم وجود ندارد.',
            'computer_id.exists'         => 'کامپیوتر انتخاب‌شده در سیستم وجود ندارد.',
            'attempt_id.exists'          => 'Attempt انتخاب‌شده در سیستم وجود ندارد.',
            'occurred_at.before_or_equal' => 'زمان وقوع نمی‌تواند در آینده باشد.',
        ];
    }
}