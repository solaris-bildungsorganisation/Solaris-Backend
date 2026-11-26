<?php

namespace App\Http\Requests\Admin\I18n;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Language::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'size:2',
                'regex:/^[a-z]{2}$/',
                Rule::unique('languages', 'code'),
            ],

            'name' => [
                'required',
                'array',
                'min:1',
            ],

            'name.*' => [
                'required',
                'string',
                'max:100',
            ],

            'native_name' => [
                'required',
                'string',
                'max:100',
            ],

            'direction' => [
                'required',
                Rule::in(['rtl', 'ltr']),
            ],

            'is_interface' => [
                'sometimes',
                'boolean',
            ],

            'is_content' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
                'max:65535',
            ],
        ];
    }
}
