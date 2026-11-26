<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UiTranslationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'group' => $this->group,
            'key' => $this->key,
            'value' => $this->value,
            'language' => $this->whenLoaded('language', function () {
                return [
                    'code' => $this->language->code,
                    'name' => $this->language->name,
                    'direction' => $this->language->direction,
                ];
            }),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
