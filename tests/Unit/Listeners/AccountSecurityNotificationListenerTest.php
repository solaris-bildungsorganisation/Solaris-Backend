<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Events\UserRolesChanged;
use App\Events\UserStatusChanged;
use App\Listeners\SendAccountStatusChangedNotification;
use App\Listeners\SendUserRolesChangedNotification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Mockery;
use Tests\TestCase;
use RuntimeException;

class AccountSecurityNotificationListenerTest extends TestCase
{
    public function test_status_changed_listener_sends_notification(): void
    {
        $user = new User();
        $service = Mockery::mock(NotificationService::class);

        $service->shouldReceive('send')
            ->once()
            ->with($user, 'account.status_changed');

        $listener = new SendAccountStatusChangedNotification($service);

        $listener->handle(new UserStatusChanged(
            user: $user,
            oldStatus: 'active',
            newStatus: 'suspended',
            reason: 'Security review',
            actorId: 1,
        ));
    }

    public function test_roles_changed_listener_sends_notification(): void
    {
        $user = new User();
        $service = Mockery::mock(NotificationService::class);

        $service->shouldReceive('send')
            ->once()
            ->with($user, 'security.role_changed');

        $listener = new SendUserRolesChangedNotification($service);

        $listener->handle(new UserRolesChanged(
            user: $user,
            action: 'assigned',
            role: 'admin',
            scopeId: 1,
            actorId: 1,
        ));
    }

    public function test_status_notification_failure_does_not_escape_listener(): void
    {
        $user = new User();
        $service = Mockery::mock(NotificationService::class);

        $service->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Notification failed'));

        $listener = new SendAccountStatusChangedNotification($service);

        $listener->handle(new UserStatusChanged(
            user: $user,
            oldStatus: 'active',
            newStatus: 'suspended',
            reason: 'Security review',
        ));

        $this->assertTrue(true);
    }

    public function test_roles_notification_failure_does_not_escape_listener(): void
    {
        $user = new User();
        $service = Mockery::mock(NotificationService::class);

        $service->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Notification failed'));

        $listener = new SendUserRolesChangedNotification($service);

        $listener->handle(new UserRolesChanged(
            user: $user,
            action: 'revoked',
            role: 'admin',
            scopeId: 1,
        ));

        $this->assertTrue(true);
    }
}
