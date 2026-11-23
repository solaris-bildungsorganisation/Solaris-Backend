<?php

namespace App\Http\Requests\Org;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\OrganizationType;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
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
        $types = array_map(fn (OrganizationType $type) => $type->value, OrganizationType::cases());
        $countryId = $this->input('country_id');
  return [
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in($types)],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'region_id' => [
                'nullable',
                'integer',
                Rule::exists('regions', 'id')->where(fn ($query) => $query->where('country_id', $countryId)),
            ],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.ar' => ['nullable', 'string'],
            'description.en' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
