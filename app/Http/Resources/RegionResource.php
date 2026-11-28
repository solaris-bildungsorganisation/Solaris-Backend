<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $region = $this->resource;
        return [
            'id' => $region->id,
            'country_id' => $region->country_id,
            'parent_id' => $region->parent_id,
            'type' => $region->type,
            'code' => $region->code,
            'name' => $region->getTranslation('name', $request->getLocale()),
            'translations' => $region->getTranslations('name'),
            'is_active' => $region->is_active,
            'sort_order' => $region->sort_order,
            'country' => $this->whenLoaded('country', fn () => $region->country ? [
                'id' => $region->country->id,
                'iso2' => $region->country->iso2,
                'name' => $region->country->getTranslation('name', $request->getLocale()),
            ] : null),
            'parent' => $this->whenLoaded('parent', fn () => $region->parent ? [
                'id' => $region->parent->id,
                'type' => $region->parent->type,
                'name' => $region->parent->getTranslation('name', $request->getLocale()),
            ] : null),
            'created_at' => $region->created_at,
            'updated_at' => $region->updated_at,
        ];
    }
}
