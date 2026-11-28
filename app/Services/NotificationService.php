<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function list(User $user, bool $unreadOnly, ?string $category, int $perPage = 15): LengthAwarePaginator
    {
        return $user->notifications()
            ->latest()
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->when($category, fn ($q) => $q->where('data->category', $category))
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function find(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    public function markRead(DatabaseNotification $notification): DatabaseNotification
    {
        $notification->markAsRead();

        return $notification->refresh();
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function delete(DatabaseNotification $notification): void
    {
        $notification->delete();
    }
}
