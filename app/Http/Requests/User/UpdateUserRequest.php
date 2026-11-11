<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Validation برای ویرایش کاربر Staff.
 * بند 34 SRS — سیاست رمز عبور.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('user'));
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name'      => ['sometimes', 'string', 'max:255'],
            'username'  => ['sometimes', 'string', 'max:100', 'unique:users,username,' . $userId],
            'email'     => ['nullable', 'email', 'max:255', 'unique:users,email,' . $userId],
            'password'  => ['nullable', 'string', Password::min(8)->letters()->numbers()],
            'role_id'   => ['sometimes', 'integer', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'این نام کاربری قبلاً ثبت شده است.',
            'email.unique'    => 'این ایمیل قبلاً ثبت شده است.',
            'role_id.exists'  => 'نقش انتخاب‌شده معتبر نیست.',
        ];
    }
}