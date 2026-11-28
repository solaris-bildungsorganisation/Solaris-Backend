<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicSuccessStoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = $request->getPreferredLanguage(['ar', 'en', 'de'])
            ?? config('app.fallback_locale', 'en');

        if (! in_array($locale, ['ar', 'en', 'de'], true)) {
            $locale = 'en';
        }

        return [
            'uuid' => $this->uuid,
            'title' => $this->getTranslation('title', $locale),
            'summary' => $this->getTranslation('summary', $locale),
            'body' => $this->getTranslation('body', $locale),
            'locale' => $locale,
            'published_at' => $this->published_at?->toISOString(),
        ];
    }
}
