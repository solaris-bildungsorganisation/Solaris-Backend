<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentTokenId = $request->user()?->currentAccessToken()?->id;

        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'ip_address' => $this->ip_address,
            'last_activity_at' => $this->last_activity_at,
            'expires_at' => $this->expires_at,
            'current' => $this->personal_access_token_id === $currentTokenId,
        ];
    }
}
