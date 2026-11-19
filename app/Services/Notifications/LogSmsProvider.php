<?php

namespace App\Services\Notifications;

use App\Contracts\SmsProvider;
use Illuminate\Support\Facades\Log;

class LogSmsProvider implements SmsProvider
{
    public function send(string $phone, string $message): mixed
    {
        unset($phone, $message);

        Log::channel(config('logging.default'))->info('SMS notification processed by log provider.', [
            'provider' => 'log',
        ]);

        return [
            'provider' => 'log',
            'message_id' => null,
        ];
    }
}
