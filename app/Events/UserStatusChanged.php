<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly string $reason,
        public readonly int|string|null $actorId = null,
    ) {}
}
