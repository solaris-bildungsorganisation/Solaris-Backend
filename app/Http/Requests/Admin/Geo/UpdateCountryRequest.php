<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Country;
use Illuminate\Validation\Rule;



class UpdateCountryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $country = $this->route('country');
        return $country instanceof Country && ($this->user()?->can('update', $country) ?? false);
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
       $country = $this->route('country');
        $id = $country instanceof Country ? $country->id : null;
        return [
            'iso2' => ['sometimes', 'required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'iso2')->ignore($id)],
            'iso3' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/', Rule::unique('countries', 'iso3')->ignore($id)],
            'name' => ['sometimes', 'required', 'array:ar,en,de'],
            'name.ar' => ['required_with:name', 'string', 'max:255'],
            'name.en' => ['required_with:name', 'string', 'max:255'],
            'name.de' => ['required_with:name', 'string', 'max:255'],
            'phone_code' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^\+?[0-9]{1,7}$/'],
            'default_language_id' => ['sometimes', 'nullable', 'integer', Rule::exists('languages', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'policy_group' => ['sometimes', 'required', Rule::in(['syria', 'stable_market', 'standard'])],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
