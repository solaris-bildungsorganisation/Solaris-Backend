<?php

namespace App\Http\Requests\Me;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // الصلاحية بتتفحص بالـ Controller عبر الـ Policy
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1'],
            'preferences.*.category' => ['required', 'string', 'max:30'],
            'preferences.*.channel' => ['required', 'string', 'max:30'],
            'preferences.*.is_enabled' => ['required', 'boolean'],
        ];
    }
}
