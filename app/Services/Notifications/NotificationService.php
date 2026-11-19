<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Language;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class NotificationService
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function send(User|iterable $users, string $typeKey, array $data = [], array $options = []): string
    {
        $notificationType = $this->getValidType($typeKey);
        $lastUuid = '';

        $notifiables = $users instanceof User ? [$users] : $users;

        foreach ($notifiables as $notifiable) {
            if (! $notifiable instanceof User) {
                throw new InvalidArgumentException('Notification recipients must be User instances.');
            }

            $lastUuid = $this->sendToUser(
                $notifiable,
                $notificationType,
                $data,
                $options
            );
        }

        return $lastUuid;
    }

    private function getValidType(string $typeKey): NotificationType
    {
        $type = NotificationType::where('key', $typeKey)->where('is_active', true)->first();
        if (! $type) {
            throw new InvalidArgumentException("Notification type [{$typeKey}] not found or inactive.");
        }

        return $type;
    }

    private function sendToUser(
        User $notifiable,
        NotificationType $notificationType,
        array $data,
        array $options
    ): string {
        $notificationUuid = (string) Str::uuid();
        $activeChannels = NotificationChannel::query()
            ->where('is_active', true)
            ->get();

        $activeByKey = $activeChannels->keyBy('key');
        $defaultChannels = \is_array($notificationType->default_channels)
            ? $notificationType->default_channels
            : [];

        $targetChannels = [];
        foreach ($defaultChannels as $channelKey) {
            if (\is_string($channelKey) && $activeByKey->has($channelKey)) {
                $targetChannels[] = $activeByKey->get($channelKey);
            }
        }

        $acceptedDrivers = [];
        $renderedTemplates = [];
        $channelStatuses = [];
        $templatesByChannelId = [];
        $safeData = $this->removeSecrets($data);

        foreach ($targetChannels as $channel) {
            $channelKey = (string) $channel->key;
            if (! empty($options['secret']) && ! in_array($channelKey, ['email', 'sms'], true)) {
                continue;
            }
            if (! $this->passesPreferences($notifiable, $notificationType, $channel)) {
                $channelStatuses[$channelKey] = 'skipped_preference';

                continue;
            }

            if (! $this->passesSensitivity($notificationType, $channel)) {
                $channelStatuses[$channelKey] = 'skipped_sensitivity';

                continue;
            }

            $template = $this->resolveTemplate(
                $notificationType,
                $channel,
                $notifiable
            );

            if (! $template) {
                $channelStatuses[$channelKey] = 'skipped_missing_template';

                continue;
            }

            try {
                $driver = (string) $channel->driver;
                $renderData = $driver === 'database' ? $safeData : $data;
                $renderedTemplates[$driver] = [
                    'subject' => $template->subject
                        ? $this->renderer->render((string) $template->subject, $renderData)
                        : null,
                    'body' => $this->renderer->render((string) $template->body, $renderData),
                    'template_id' => (int) $template->id,
                ];
                $templatesByChannelId[(int) $channel->id] = (int) $template->id;
                $acceptedDrivers[] = $driver;
                $channelStatuses[$channelKey] = 'queued';
            } catch (\Throwable $exception) {
                Log::warning('Notification template rendering failed', [
                    'notification_type' => $notificationType->key,
                    'channel' => $channelKey,
                    'template_id' => $template->id,
                ]);
                $channelStatuses[$channelKey] = 'skipped_render_error';
            }
        }

        $acceptedDrivers = array_values(array_unique($acceptedDrivers));
        $dispatchOptions = $options;
        if (
            ! \in_array('mail', $acceptedDrivers, true)
            && ! \in_array('sms', $acceptedDrivers, true)
        ) {
            unset($dispatchOptions['secret']);
        }

        DB::transaction(function () use ($notifiable, $notificationType, $notificationUuid, $targetChannels, $channelStatuses, $templatesByChannelId): void {
            foreach ($targetChannels as $channel) {
                $channelKey = (string) $channel->key;

                NotificationDelivery::create([
                    'notification_uuid' => $notificationUuid,
                    'user_id' => (int) $notifiable->id,
                    'notification_type_id' => (int) $notificationType->id,
                    'channel_id' => (int) $channel->id,
                    'template_id' => $templatesByChannelId[(int) $channel->id] ?? null,
                    'status' => $channelStatuses[$channelKey] ?? 'skipped_unknown',
                ]);
            }
        });

        if (! empty($acceptedDrivers)) {
            $notification = new PlatformNotification(
                notificationUuid: $notificationUuid,
                typeKey: $notificationType->key,
                channels: $acceptedDrivers,
                renderedTemplates: $renderedTemplates,
                data: $safeData,
                options: $dispatchOptions
            );

            $notifiable->notify($notification);
        }

        return $notificationUuid;
    }

    private function passesPreferences(
        User $notifiable,
        NotificationType $type,
        NotificationChannel $channel
    ): bool {
        if ((bool) $type->is_mandatory) {
            return true;
        }

        $preference = NotificationPreference::query()
            ->where('user_id', (int) $notifiable->id)
            ->where('category', (string) $type->category)
            ->where('channel_id', (int) $channel->id)
            ->first();

        return ! $preference || (bool) $preference->is_enabled;
    }

    private function passesSensitivity(
        NotificationType $type,
        NotificationChannel $channel
    ): bool {
        if ((string) $type->sensitivity !== 'sensitive') {
            return true;
        }

        return (bool) $channel->supports_sensitive;
    }

    private function resolveTemplate(
        NotificationType $type,
        NotificationChannel $channel,
        User $notifiable
    ): ?NotificationTemplate {
        $languageId = $this->resolveInterfaceLanguageId($notifiable);
        $arabicLanguageId = Language::query()
            ->where('code', 'ar')
            ->value('id');

        $languageIds = $arabicLanguageId
            ? array_values(array_unique([$languageId, (int) $arabicLanguageId]))
            : ($languageId ? [$languageId] : []);

        foreach ($languageIds as $languageIdCandidate) {
            $template = NotificationTemplate::query()
                ->where('notification_type_id', (int) $type->id)
                ->where('channel_id', (int) $channel->id)
                ->where('language_id', $languageIdCandidate)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    private function resolveInterfaceLanguageId(User $notifiable): ?int
    {
        $languageId = $notifiable->interface_language_id;

        return $languageId === null ? null : (int) $languageId;
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
