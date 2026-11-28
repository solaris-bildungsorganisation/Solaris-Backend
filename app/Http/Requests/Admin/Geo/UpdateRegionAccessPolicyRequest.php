<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Models\Region;

class UpdateRegionAccessPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
         $policy = $this->route('policy');
        return $policy instanceof RegionAccessPolicy && ($this->user()?->can('update', $policy) ?? false);

    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
         return [
            'policy_key' => ['sometimes', 'required', 'string', 'max:80', Rule::in(array_keys(config('solaris.region_policy_keys', [])))],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'value' => ['sometimes', 'required'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
        ];
    }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policy = $this->route('policy');
            if (! $policy instanceof RegionAccessPolicy) return;
            $data = $validator->getData();
            $key = $data['policy_key'] ?? $policy->policy_key;
            $value = $data['value'] ?? $policy->value;
            $countryId = array_key_exists('country_id', $data) ? $data['country_id'] : $policy->country_id;
            $regionId = array_key_exists('region_id', $data) ? $data['region_id'] : $policy->region_id;
            $from = array_key_exists('effective_from', $data) ? $data['effective_from'] : $policy->effective_from;
            $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $policy->effective_to;
            $type = is_string($key) ? (config('solaris.region_policy_keys', [])[$key]['type'] ?? null) : null;
            if ($type && ! $this->matchesPolicyValueType($value, $type)) {
                $validator->errors()->add('value', "قيمة السياسة لا تطابق النوع المعتمد ({$type}).");
            }
            if ($regionId !== null) {
                $region = Region::query()->find($regionId);
                if (! $region || $countryId === null || (int) $region->country_id !== (int) $countryId) {
                    $validator->errors()->add('region_id', 'المنطقة المحددة لا تتبع الدولة المحددة.');
                }
            }
            if ($from !== null && $to !== null && strtotime((string) $from) >= strtotime((string) $to)) {
                $validator->errors()->add('effective_to', 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.');
            }
            $duplicate = RegionAccessPolicy::query()->where('policy_key', $key)->where('id', '!=', $policy->id)
                ->when($countryId === null, fn ($q) => $q->whereNull('country_id'), fn ($q) => $q->where('country_id', $countryId))
                ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
                ->exists();
            if ($duplicate) $validator->errors()->add('policy_key', 'توجد سياسة بهذا المفتاح والنطاق بالفعل.');
        });
    }

    private function matchesPolicyValueType(mixed $value, string $type): bool
    {
        return match ($type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };
    }
}
