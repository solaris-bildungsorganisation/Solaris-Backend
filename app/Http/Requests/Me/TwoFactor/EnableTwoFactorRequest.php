<?php

namespace App\Http\Requests\Me\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/** POST /me/2fa/enable — يتطلب كلمة المرور الحالية. الصلاحية تُفحص في الـ Controller (UserPolicy). */
class EnableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
