<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notification_type_id' => $this->notification_type_id,
            'channel_id' => $this->channel_id,
            'language_id' => $this->language_id,
            'subject' => $this->subject,
            'body' => $this->body,
            'version' => $this->version,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'type' => $this->whenLoaded('type', fn () => $this->type === null ? null : [
                'id' => $this->type->id,
                'key' => $this->type->key,
                'name' => $this->type->getTranslations('name'),
                'sensitivity' => $this->type->sensitivity,
            ]),
            'channel' => $this->whenLoaded('channel', fn () => $this->channel === null ? null : [
                'id' => $this->channel->id,
                'key' => $this->channel->key,
                'name' => $this->channel->getTranslations('name'),
                'supports_sensitive' => $this->channel->supports_sensitive,
            ]),
            'language' => $this->whenLoaded('language', fn () => $this->language === null ? null : [
                'id' => $this->language->id,
                'code' => $this->language->code,
                'name' => $this->language->getTranslations('name'),
            ]),
            'updater' => $this->whenLoaded('updater', fn () => $this->updater === null ? null : [
                'uuid' => $this->updater->uuid,
                'name' => $this->updater->display_name
                    ?: trim($this->updater->first_name.' '.$this->updater->last_name),
            ]),
        ];
    }
}
