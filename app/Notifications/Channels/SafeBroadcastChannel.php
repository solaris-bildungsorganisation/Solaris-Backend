<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafeBroadcastChannel extends BroadcastChannel
{

    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $exception) {
            Log::warning('Real-time notification broadcast failed', [
                'notification_class' => $notification::class,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return null;
        }
    }
}
