<?php

namespace App\Http\Requests\Incident;

use App\Models\Incident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * اعتبارسنجی ثبت حادثه جدید.
 * Authorization از طریق IncidentPolicy کنترل می‌شود.
 * بند 26 SRS — هر حادثه باید candidate یا computer داشته باشد.
 */
class StoreIncidentRequest extends FormRequest
{
    /**
     * بررسی دسترسی از طریق Policy — نه hasPermission.
     * چون Permission رکورد جداگانه‌ای برای incidents نداریم.
     */
    public function authorize(): bool
    {
        return Gate::allows('create', Incident::class);
    }

    public function rules(): array
    {
        return [
            'candidate_id'        => ['nullable', 'integer', 'exists:candidates,id'],
            'computer_id'         => ['nullable', 'integer', 'exists:computers,id'],
            'attempt_id'          => ['nullable', 'integer', 'exists:attempts,id'],
            'problem_description' => ['required', 'string', 'min:10', 'max:2000'],
            'action_taken'        => ['nullable', 'string', 'max:2000'],
            'responsible_user_id' => ['required', 'integer', 'exists:users,id'],
            'occurred_at'         => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    /**
     * اعتبارسنجی اضافی: حداقل یکی از candidate_id یا computer_id باید پر باشد.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->candidate_id) && empty($this->computer_id)) {
                $validator->errors()->add(
                    'candidate_id',
                    'حداقل یکی از متقاضی یا کامپیوتر باید مشخص شود.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'candidate_id.exists'          => 'متقاضی انتخاب‌شده در سیستم وجود ندارد.',
            'computer_id.exists'           => 'کامپیوتر انتخاب‌شده در سیستم وجود ندارد.',
            'attempt_id.exists'            => 'Attempt انتخاب‌شده در سیستم وجود ندارد.',
            'problem_description.required' => 'توضیح مشکل الزامی است.',
            'problem_description.min'      => 'توضیح مشکل باید حداقل ۱۰ کاراکتر باشد.',
            'responsible_user_id.required' => 'مسئول حادثه الزامی است.',
            'responsible_user_id.exists'   => 'کاربر مسئول در سیستم وجود ندارد.',
            'occurred_at.before_or_equal'  => 'زمان وقوع نمی‌تواند در آینده باشد.',
        ];
    }
}