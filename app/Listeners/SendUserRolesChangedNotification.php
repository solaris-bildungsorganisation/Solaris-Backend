<?php

namespace App\Listeners;

use App\Events\UserRolesChanged;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUserRolesChangedNotification
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    public function handle(UserRolesChanged $event): void
    {
        try {
            $this->notificationService->send(
                $event->user,
                'security.role_changed'
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send user roles changed notification.', [
                'user_id' => $event->user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
