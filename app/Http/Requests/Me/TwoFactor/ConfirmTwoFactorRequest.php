<?php

namespace App\Http\Requests\Me\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/** POST /me/2fa/confirm — رمز من تطبيق المصادقة (6 أرقام). */
class ConfirmTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
