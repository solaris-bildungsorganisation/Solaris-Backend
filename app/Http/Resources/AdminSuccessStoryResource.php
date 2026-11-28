<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSuccessStoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->getTranslations('title'),
            'summary' => $this->getTranslations('summary'),
            'body' => $this->getTranslations('body'),
            'subject_user_id' => $this->subject_user_id,
            'organization_id' => $this->organization_id,
            'country_id' => $this->country_id,
            'region_id' => $this->region_id,
            'consent_status' => $this->consent_status,
            'consent_recorded_at' => $this->consent_recorded_at?->toISOString(),
            'consent_recorded_by' => $this->consent_recorded_by,
            'status' => $this->status,
            'published_at' => $this->published_at?->toISOString(),
            'created_by' => $this->created_by,
            'reviewed_by' => $this->reviewed_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
