<?php

namespace App\Listeners;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use Illuminate\Notifications\Events\NotificationSent;

class NotificationDeliverySentListener
{
    public function handle(NotificationSent $event): void
    {
        $notificationUuid = $event->notification->id ?? null;
        $userId = $event->notifiable->getKey() ?? null;

        if (! is_string($notificationUuid) || $notificationUuid === '' || ! is_numeric($userId)) {
            return;
        }

        $channels = NotificationChannel::query()
            ->where('driver', $event->channel)
            ->get();

        if ($channels->count() !== 1) {
            return;
        }

        $channel = $channels->first();

        $delivery = NotificationDelivery::query()
            ->where('notification_uuid', $notificationUuid)
            ->where('user_id', (int) $userId)
            ->where('channel_id', $channel->id)
            ->whereNotIn('status', ['skipped_preference', 'skipped_sensitivity'])
            ->latest('id')
            ->first();

        if (! $delivery) {
            return;
        }

        if ($delivery->status === 'sent') {
            return;
        }

        $response = $event->response;
        $providerMessageId = is_array($response)
            ? ($response['provider_message_id'] ?? $response['message_id'] ?? null)
            : null;

        $attempts = (int) $delivery->attempts + 1;

        $updates = [
            'status' => 'sent',
            'attempts' => $attempts,
            'provider_message_id' => is_string($providerMessageId) ? $providerMessageId : null,
            'last_error' => null,
            'sent_at' => now(),
            'failed_at' => null,
        ];

        if (is_array($response) && isset($response['provider'])) {
            $updates['provider'] = (string) $response['provider'];
        }

        $delivery->forceFill($updates)->save();
    }
}
