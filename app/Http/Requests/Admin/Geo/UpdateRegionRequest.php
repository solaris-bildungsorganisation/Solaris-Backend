<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Region;
use App\Policies\ScopeGuard;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRegionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
          $region = $this->route('region');
        return $region instanceof Region && ($this->user()?->can('update', $region) ?? false);
    }
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $region = $this->route('region');
        $id = $region instanceof Region ? $region->id : null;
        $countryId = $this->input('country_id', $region instanceof Region ? $region->country_id : null);
        return [
            'country_id' => ['sometimes', 'required', 'integer', 'exists:countries,id'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'type' => ['sometimes', 'required', Rule::in(['governorate', 'district', 'city', 'state'])],
            'code' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('regions', 'code')->where(fn ($q) => $q->where('country_id', $countryId))->ignore($id)],
            'name' => ['sometimes', 'required', 'array:ar,en,de'],
            'name.ar' => ['required_with:name', 'string', 'max:255'],
            'name.en' => ['required_with:name', 'string', 'max:255'],
            'name.de' => ['required_with:name', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $parentId = $this->input('parent_id');
            if ($parentId === null) return;
            $region = $this->route('region');
            $countryId = (int) $this->input('country_id', $region instanceof Region ? $region->country_id : 0);
            $parent = Region::query()->find($parentId);
            if ($parent && (int) $parent->country_id !== $countryId) {
                $validator->errors()->add('parent_id', 'يجب أن تكون المنطقة الأب ضمن الدولة نفسها.');
            }
            if ($region instanceof Region && in_array((int) $parentId, ScopeGuard::regionSubtree((int) $region->id), true)) {
                $validator->errors()->add('parent_id', 'لا يمكن تعيين المنطقة نفسها أو أحد أبنائها كأب.');
            }
        });
    }
}
