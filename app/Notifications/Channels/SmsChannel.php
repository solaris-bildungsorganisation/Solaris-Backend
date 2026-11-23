<?php

namespace App\Notifications\Channels;

use App\Contracts\SmsProvider;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use ReflectionObject;

class SmsChannel
{
    public function __construct(private readonly SmsProvider $provider) {}

    public function send(object $notifiable, Notification $notification): mixed
    {
        $phone = $this->resolvePhone($notifiable);

        if (! is_string($phone) || trim($phone) === '') {
            throw new InvalidArgumentException('SMS delivery requires a recipient phone number.');
        }

        $message = $this->resolveMessage($notifiable, $notification);

        if (trim($message) === '') {
            throw new InvalidArgumentException('SMS delivery requires a rendered message.');
        }

        return $this->provider->send($phone, $message);
    }

    private function resolvePhone(object $notifiable): mixed
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('sms');
            if (is_string($route) && trim($route) !== '') {
                return $route;
            }
        }

        return $notifiable->phone ?? null;
    }

    private function resolveMessage(object $notifiable, Notification $notification): string
    {
        if (method_exists($notification, 'toSms')) {
            $sms = $notification->toSms($notifiable);

            return $this->normalizeMessage($sms);
        }

        foreach (['smsText', 'smsMessage', 'sms_body'] as $property) {
            if (isset($notification->{$property})) {
                return $this->normalizeMessage($notification->{$property});
            }
        }

        $reflection = new ReflectionObject($notification);

        if ($reflection->hasProperty('renderedTemplates')) {
            $property = $reflection->getProperty('renderedTemplates');
            $property->setAccessible(true);
            $templates = $property->getValue($notification);

            if (is_array($templates)) {
                $sms = $templates['sms'] ?? $templates['sms_channel'] ?? null;

                if (is_array($sms)) {
                    return trim((string) ($sms['body'] ?? $sms['text'] ?? $sms['message'] ?? ''));
                }

                if (is_string($sms)) {
                    return trim($sms);
                }
            }
        }

        return '';
    }

    private function normalizeMessage(mixed $sms): string
    {
        if (is_string($sms)) {
            return trim($sms);
        }

        if (is_array($sms)) {
            return trim((string) ($sms['body'] ?? $sms['text'] ?? $sms['message'] ?? ''));
        }

        return '';
    }
}
