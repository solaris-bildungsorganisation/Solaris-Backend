<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegionAccessPolicyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
       {
        $policy = $this->resource;
        return [
            'id' => $policy->id,
            'policy_key' => $policy->policy_key,
            'country_id' => $policy->country_id,
            'region_id' => $policy->region_id,
            'value' => $policy->value,
            'is_active' => $policy->is_active,
            'effective_from' => $policy->effective_from,
            'effective_to' => $policy->effective_to,
            'country' => $this->whenLoaded('country', fn () => $policy->country ? [
                'id' => $policy->country->id,
                'iso2' => $policy->country->iso2,
                'name' => $policy->country->getTranslation('name', $request->getLocale()),
            ] : null),
            'region' => $this->whenLoaded('region', fn () => $policy->region ? [
                'id' => $policy->region->id,
                'type' => $policy->region->type,
                'name' => $policy->region->getTranslation('name', $request->getLocale()),
            ] : null),
            'created_at' => $policy->created_at,
            'updated_at' => $policy->updated_at,
        ];
    }
}
