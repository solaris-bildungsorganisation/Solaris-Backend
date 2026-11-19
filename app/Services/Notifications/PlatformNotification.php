<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param string $notificationUuid
     * @param string $typeKey
     * @param array<int, string> $channels
     * @param array<string, array{subject: ?string, body: string}> $renderedTemplates
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $notificationUuid,
        private readonly string $typeKey,
        private readonly array $channels,
        private readonly array $renderedTemplates,
        private readonly array $data = [],
        private readonly array $options = [],
    ) {
        $this->id = $notificationUuid;
    }

    public function via(object $notifiable): array
    {
        unset($notifiable);
        return $this->channels;
    }

    public function toDatabase(object $notifiable): array
    {
        unset($notifiable);
        $inApp = $this->renderedTemplates['database'] ?? ['subject' => null, 'body' => ''];

        return [
            'type' => $this->typeKey,
            'subject' => $inApp['subject'],
            'body' => $inApp['body'],
            'data' => $this->removeSecrets($this->data),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        unset($notifiable);
        $mail = $this->renderedTemplates['mail'] ?? ['subject' => '', 'body' => ''];

        $message = (new MailMessage())
            ->subject((string) ($mail['subject'] ?? ''))
            ->line((string) ($mail['body'] ?? ''));

        if (!empty($this->options['secret'])) {
            $message->line((string) $this->options['secret']);
        }

        return $message;
    }

    private function removeSecrets(array $value): array
    {
        $secretKeys = [
            'secret',
            'otp',
            'pin',
            'password',
            'token',
            'verification_code',
        ];

        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array(strtolower($key), $secretKeys, true)) {
                unset($value[$key]);
                continue;
            }

            if (\is_array($item)) {
                $value[$key] = $this->removeSecrets($item);
            }
        }

        return $value;
    }
}
