<?php

namespace App\Listeners;

use App\Events\UserStatusChanged;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAccountStatusChangedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    public function handle(UserStatusChanged $event): void
    {
        try {
            $this->notificationService->send(
                $event->user,
                'account.status_changed'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send account status changed notification.', [
                'user_id' => $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
