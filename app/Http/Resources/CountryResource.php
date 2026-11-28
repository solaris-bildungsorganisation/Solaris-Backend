<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
      {
        $country = $this->resource;
        return [
            'id' => $country->id,
            'iso2' => $country->iso2,
            'iso3' => $country->iso3,
            'name' => $country->getTranslation('name', $request->getLocale()),
            'translations' => $country->getTranslations('name'),
            'phone_code' => $country->phone_code,
            'default_language_id' => $country->default_language_id,
            'default_language' => $this->whenLoaded('defaultLanguage', fn () => $country->defaultLanguage ? [
                'id' => $country->defaultLanguage->id,
                'code' => $country->defaultLanguage->code,
                'name' => $country->defaultLanguage->getTranslation('name', $request->getLocale()),
                'native_name' => $country->defaultLanguage->native_name,
            ] : null),
            'policy_group' => $country->policy_group,
            'is_active' => $country->is_active,
            'created_at' => $country->created_at,
            'updated_at' => $country->updated_at,
        ];
    }
}
