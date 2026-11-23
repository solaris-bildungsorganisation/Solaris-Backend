<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);

Broadcast::channel('users.{uuid}', function ($user, string $uuid) {
    return (string) $user->uuid === (string) $uuid;
}, ['guards' => ['sanctum']]);
