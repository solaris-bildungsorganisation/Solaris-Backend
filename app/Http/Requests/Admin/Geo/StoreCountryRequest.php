<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Country;
use Illuminate\Validation\Rule;
class StoreCountryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
return $this->user()?->can('create', Country::class) ?? false;
}
protected function prepareForValidation(): void
    {
        $this->merge([
            'iso2' => is_string($this->input('iso2')) ? strtoupper(trim($this->input('iso2'))) : $this->input('iso2'),
            'iso3' => is_string($this->input('iso3')) ? strtoupper(trim($this->input('iso3'))) : $this->input('iso3'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
          return [
            'iso2' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'iso2')],
            'iso3' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('countries', 'iso3')],
            'name' => ['required', 'array:ar,en,de'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.de' => ['required', 'string', 'max:255'],
            'phone_code' => ['nullable', 'string', 'max:8', 'regex:/^\+?[0-9]{1,7}$/'],
            'default_language_id' => ['nullable', 'integer', Rule::exists('languages', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'policy_group' => ['sometimes', 'required', Rule::in(['syria', 'stable_market', 'standard'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
