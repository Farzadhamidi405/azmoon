<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Validation برای ایجاد کاربر Staff جدید.
 * بند 34 SRS — سیاست رمز عبور.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', \App\Models\User::class);
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'email'    => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role_id'  => ['required', 'integer', 'exists:roles,id'],
            'is_active'=> ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'نام الزامی است.',
            'username.required' => 'نام کاربری الزامی است.',
            'username.unique'   => 'این نام کاربری قبلاً ثبت شده است.',
            'email.unique'      => 'این ایمیل قبلاً ثبت شده است.',
            'password.required' => 'رمز عبور الزامی است.',
            'role_id.required'  => 'نقش الزامی است.',
            'role_id.exists'    => 'نقش انتخاب‌شده معتبر نیست.',
        ];
    }
}