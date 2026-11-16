<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
 {
 return [
 'first_name' => ['required', 'string', 'max:100'],
 'last_name' => ['required', 'string', 'max:100'],
 'display_name' => ['nullable', 'string', 'max:150'],
 'email' =>
 ['nullable', 'required_without:phone',
  'email', 'unique:users,email'],
'phone' => ['nullable', 'required_without:email',
 'string', 'unique:users,phone'],
'account_type' => ['nullable', 'string'],
'password' => ['required', 'string', 'min:12', 'confirmed'],
];
}

}

