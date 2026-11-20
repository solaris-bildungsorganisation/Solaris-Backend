<?php

namespace App\Listeners;

use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use Illuminate\Notifications\Events\NotificationFailed;

class NotificationDeliveryFailedListener
{
    public function handle(NotificationFailed $event): void
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

        $attempts = (int) $delivery->attempts + 1;

        $delivery->forceFill([
            'status' => 'failed',
            'attempts' => $attempts,
            'last_error' => 'Notification delivery failed',
            'failed_at' => now(),
            'sent_at' => null,
        ])->save();
    }
}
