<?php

namespace App\Http\Requests\Me\TwoFactor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/2fa/recovery-codes و DELETE /me/2fa — كلمة المرور الحالية + رمز.
 * الرمز: رمز التطبيق (6 أرقام) أو رمز استرداد (xxxxx-xxxxx).
 */
class TwoFactorSensitiveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
