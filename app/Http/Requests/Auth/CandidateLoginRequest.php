<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CandidateLoginRequest extends FormRequest
{
    /**
     * All candidate login requests are allowed through.
     * Authorization is handled by guard and account status checks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate candidate login credentials.
     * Candidates log in with username or candidate_code plus password.
     */
    public function rules(): array
    {
        return [
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required'    => 'کد متقاضی یا نام کاربری الزامی است.',
            'password.required' => 'رمز عبور الزامی است.',
        ];
    }
}