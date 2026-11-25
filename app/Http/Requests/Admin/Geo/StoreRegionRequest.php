<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Region;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRegionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Region::class) ?? false;
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
         return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'parent_id' => ['nullable', 'integer', 'exists:regions,id'],
            'type' => ['sometimes', 'required', Rule::in(['governorate', 'district', 'city', 'state'])],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('regions', 'code')->where(fn ($q) => $q->where('country_id', $this->input('country_id')))],
            'name' => ['required', 'array:ar,en,de'],
            'name.ar' => ['required', 'string', 'max:255'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.de' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $parentId = $this->input('parent_id');
            if ($parentId === null) return;
            $parent = Region::query()->find($parentId);
            if ($parent && (int) $parent->country_id !== (int) $this->input('country_id')) {
                $validator->errors()->add('parent_id', 'يجب أن تكون المنطقة الأب ضمن الدولة نفسها.');
            }
        });
    }
}
