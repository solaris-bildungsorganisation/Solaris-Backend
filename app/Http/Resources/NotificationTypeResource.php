<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'category' => $this->category,
            'name' => $this->getTranslations('name'),
            'description' => $this->getTranslations('description'),
            'sensitivity' => $this->sensitivity,
            'is_mandatory' => $this->is_mandatory,
            'default_channels' => $this->default_channels,
            'is_active' => $this->is_active,
            'active_templates_count' => $this->whenNotNull($this->active_templates_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
