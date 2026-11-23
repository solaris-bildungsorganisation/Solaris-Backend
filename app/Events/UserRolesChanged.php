<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserRolesChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $action,
        public readonly string $role,
        public readonly int $scopeId,
        public readonly int|string|null $actorId = null,
    ) {}
}
