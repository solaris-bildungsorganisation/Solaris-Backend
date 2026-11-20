<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'user_id' => $this->user_id,

            'notification_type_id' => $this->notification_type_id,

            'channel_id' => $this->channel_id,

            'template_id' => $this->template_id,

            'status' => $this->status,

            'attempts' => $this->attempts,

            'provider' => $this->provider,

            'sent_at' => $this->sent_at,

            'delivered_at' => $this->delivered_at,

            'failed_at' => $this->failed_at,

           'last_error' => $this->when(
    $request->routeIs('admin.notification-deliveries.show'),
    $this->last_error
),

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,

            'type' => $this->whenLoaded('type', function () {
                return [
                    'id' => $this->type->id,
                    'key' => $this->type->key,
                    'name' => $this->type->name,
                    'category' => $this->type->category,
                ];
            }),

            'channel' => $this->whenLoaded('channel', function () {
                return [
                    'id' => $this->channel->id,
                    'key' => $this->channel->key,
                    'name' => $this->channel->name,
                ];
            }),
        ];
    }
}
