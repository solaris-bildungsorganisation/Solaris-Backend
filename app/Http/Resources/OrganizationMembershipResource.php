<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationMembershipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'membership_type' => $this->membership_type,
            'status' => $this->status,
            'title' => $this->title,
            'joined_at' => $this->joined_at,
            'organization' => new OrganizationResource($this->whenLoaded('organization')),
        ];
    }
}
