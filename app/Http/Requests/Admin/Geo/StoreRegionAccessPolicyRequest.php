<?php

namespace App\Http\Requests\Admin\Geo;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Region;
use App\Models\RegionAccessPolicy;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;



class StoreRegionAccessPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
       return $this->user()?->can('create', RegionAccessPolicy::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
       return [
            'policy_key' => ['required', 'string', 'max:80', Rule::in(array_keys(config('solaris.region_policy_keys', [])))],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'value' => ['required'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
        ];
    }
     public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();
            $key = $data['policy_key'] ?? null;
            $type = is_string($key) ? (config('solaris.region_policy_keys', [])[$key]['type'] ?? null) : null;
            if ($type && ! $this->matchesPolicyValueType($data['value'] ?? null, $type)) {
                $validator->errors()->add('value', "قيمة السياسة لا تطابق النوع المعتمد ({$type}).");
            }
            $this->checkRegionCountry($validator, $data['country_id'] ?? null, $data['region_id'] ?? null);
            $this->checkWindow($validator, $data['effective_from'] ?? null, $data['effective_to'] ?? null);
            if (is_string($key) && $this->sameScopeExists($key, $data['country_id'] ?? null, $data['region_id'] ?? null)) {
                $validator->errors()->add('policy_key', 'توجد سياسة بهذا المفتاح والنطاق بالفعل.');
            }
        });
    }

    private function checkRegionCountry(Validator $validator, mixed $countryId, mixed $regionId): void
    {
        if ($regionId === null) return;
        $region = Region::query()->find($regionId);
        if (! $region || $countryId === null || (int) $region->country_id !== (int) $countryId) {
            $validator->errors()->add('region_id', 'المنطقة المحددة لا تتبع الدولة المحددة.');
        }
    }

    private function checkWindow(Validator $validator, mixed $from, mixed $to): void
    {
        if ($from !== null && $to !== null && strtotime((string) $from) >= strtotime((string) $to)) {
            $validator->errors()->add('effective_to', 'يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.');
        }
    }

    private function sameScopeExists(string $key, mixed $countryId, mixed $regionId): bool
    {
        return RegionAccessPolicy::query()->where('policy_key', $key)
            ->when($countryId === null, fn ($q) => $q->whereNull('country_id'), fn ($q) => $q->where('country_id', $countryId))
            ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
            ->exists();
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
