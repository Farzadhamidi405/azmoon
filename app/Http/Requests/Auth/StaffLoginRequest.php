<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class StaffLoginRequest extends FormRequest
{
    /**
     * All staff login requests are allowed through.
     * Authorization is handled by guard and account status checks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate login credentials.
     * Staff can log in with username or email plus password.
     */
    public function rules(): array
    {
        return [
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }

    /**
     * Custom error messages in Dari/English.
     */
    public function messages(): array
    {
        return [
            'login.required'    => 'نام کاربری یا ایمیل الزامی است.',
            'password.required' => 'رمز عبور الزامی است.',
            'password.min'      => 'رمز عبور باید حداقل ۶ کاراکتر باشد.',
        ];
    }
}