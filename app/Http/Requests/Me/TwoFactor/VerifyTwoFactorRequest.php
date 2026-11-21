<?php

namespace App\Http\Requests\Me\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/** POST /me/2fa/verify (step-up) — رمز التطبيق (6 أرقام) أو رمز استرداد. */
class VerifyTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
