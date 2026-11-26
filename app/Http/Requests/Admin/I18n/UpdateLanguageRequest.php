<?php

namespace App\Http\Requests\Admin\I18n;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $language = $this->route('language');

        return $language instanceof Language
            && ($this->user()?->can('update', $language) ?? false);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'array', 'min:1'],
            'name.*' => ['required', 'string', 'max:100'],
            'native_name' => ['sometimes', 'required', 'string', 'max:100'],
            'direction' => ['sometimes', 'required', Rule::in(['rtl', 'ltr'])],
            'is_interface' => ['sometimes', 'boolean'],
            'is_content' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
