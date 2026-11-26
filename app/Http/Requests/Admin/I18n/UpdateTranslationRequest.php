<?php

namespace App\Http\Requests\Admin\I18n;

use App\Models\UiTranslation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', UiTranslation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'string', 'max:50'],
            'key' => ['required', 'string', 'max:150'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.language' => [
                'required',
                'string',
                'size:2',
                'distinct',
                Rule::exists('languages', 'code'),
            ],
            'translations.*.value' => ['required', 'string'],
        ];
    }
}
