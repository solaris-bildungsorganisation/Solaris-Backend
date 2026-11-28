<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Http\Resources\NotificationResource;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     * @param  array<string, array{subject: ?string, body: string}>  $renderedTemplates
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        string $notificationUuid,
        private readonly string $typeKey,
        private readonly array $channels,
        private readonly array $renderedTemplates,
        private readonly array $data = [],
        private readonly array $options = [],
        private readonly ?string $recipientUuid = null,
    ) {
        $this->id = $notificationUuid;
    }

    public function via(object $notifiable): array
    {
        unset($notifiable);

        $channels = $this->channels;
        if (\in_array('database', $channels, true) && ! \in_array('broadcast', $channels, true)) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function broadcastOn($notifiable = null)
    {
        unset($notifiable);

        if (!$this->recipientUuid) {
            return [];
        }

        return [new PrivateChannel('users.'.(string) $this->recipientUuid)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        try {
            $notification = $notifiable->notifications()->find($this->id);

            if ($notification) {
                $payload = (new NotificationResource($notification))->resolve();
            } else {
                $payload = $this->removeSecrets($this->toDatabase($notifiable));
            }
        } catch (Throwable $exception) {
            Log::warning('Building real-time notification payload failed', [
                'notification_class' => static::class,
                'exception' => $exception::class,
            ]);
            $payload = $this->removeSecrets($this->toDatabase($notifiable));
        }

        $payload = $this->removeSecrets(\is_array($payload) ? $payload : []);

        try {
            $payload['unread_count'] = $notifiable->unreadNotifications()->count();
        } catch (Throwable $exception) {
            Log::warning('Calculating unread notification count failed', [
                'notification_class' => static::class,
                'exception' => $exception::class,
            ]);
            $payload['unread_count'] = 0;
        }

        return new BroadcastMessage($payload);
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

        $message = (new MailMessage)
            ->subject((string) ($mail['subject'] ?? ''))
            ->line((string) ($mail['body'] ?? ''));

        if (! empty($this->options['secret'])) {
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
