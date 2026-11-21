<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->getTranslations('name'),
            'slug' => $this->slug,
            'type' => $this->type,
            'status' => $this->status,
            'country_id' => $this->country_id,
            'region_id' => $this->region_id,
            'registration_number' => $this->registration_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address,
            'description' => $this->description ? $this->getTranslations('description') : null,
            'settings' => $this->settings,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
            'members_count' => $this->whenCounted('members'),
        ];
    }
}
