<?php

namespace App\Http\Requests\SubjectAccess;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation برای تخصیص دسترسی یک مضمون به کاربر.
 * بند 60.6 SRS — دسترسی مضمون‌محور برای Question Manager/Reviewer.
 *
 * قوانین:
 * - کاربر هدف باید وجود داشته باشد.
 * - کاربر هدف باید نقش Question Manager یا Reviewer داشته باشد
 *   (تخصیص دسترسی برای سایر نقش‌ها بی‌معناست).
 * - هر کاربر برای هر مضمون فقط یک رکورد دسترسی می‌تواند داشته باشد
 *   (Unique Constraint در DB + بررسی اینجا برای پیام خطای واضح).
 */
class StoreSubjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('assignAccess', $this->route('subject'));
    }

    public function rules(): array
    {
        $subjectId = $this->route('subject')?->id;

        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    $user = User::with('role')->find($value);

                    if ($user && !in_array($user->role?->name, ['Question Manager', 'Reviewer'])) {
                        $fail('فقط کاربران با نقش Question Manager یا Reviewer می‌توانند دسترسی مضمون دریافت کنند.');
                    }
                },
                Rule::unique('user_subject_access', 'user_id')->where(
                    fn ($query) => $query->where('subject_id', $subjectId)
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'کاربر الزامی است.',
            'user_id.exists'   => 'کاربر انتخاب‌شده معتبر نیست.',
            'user_id.unique'   => 'این کاربر قبلاً به این مضمون دسترسی دارد.',
        ];
    }
}