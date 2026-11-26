<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class NotificationAdminService
{
    private const TEMPLATE_RELATIONS = ['type', 'channel', 'language', 'updater'];

    public function __construct(private readonly TemplateRenderer $templateRenderer) {}

    public function listTypes(): Collection
    {
        return NotificationType::query()
            ->withCount([
                'templates as active_templates_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('category')
            ->orderBy('key')
            ->get();
    }

    public function updateType(NotificationType $type, array $data, User $actor): NotificationType
    {
        $attributes = Arr::only($data, ['name', 'description', 'default_channels', 'is_active']);

        return DB::transaction(function () use ($type, $attributes, $actor): NotificationType {
            $type = NotificationType::query()
                ->whereKey($type->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $this->typeAuditValues($type, array_keys($attributes));
            $type->fill($attributes);

            if ($type->isDirty(array_keys($attributes))) {
                $type->save();

                $this->logAdminActivity(
                    $actor,
                    $type,
                    [
                        'old' => $oldValues,
                        'new' => $this->typeAuditValues($type, array_keys($attributes)),
                    ],
                    'تم تعديل نوع إشعار'
                );
            }

            return $type->loadCount([
                'templates as active_templates_count' => fn ($query) => $query->where('is_active', true),
            ]);
        });
    }

    public function listTemplates(array $filters = []): Collection
    {
        $query = NotificationTemplate::query()
            ->with(self::TEMPLATE_RELATIONS);

        foreach (['type' => 'notification_type_id', 'channel' => 'channel_id', 'language' => 'language_id'] as $filter => $column) {
            if (! array_key_exists($filter, $filters) || $filters[$filter] === null || $filters[$filter] === '') {
                continue;
            }

            $value = $filters[$filter];

            if (is_numeric($value)) {
                $query->where($column, (int) $value);

                continue;
            }

            $relationAndColumn = match ($filter) {
                'type' => ['type', 'key'],
                'channel' => ['channel', 'key'],
                'language' => ['language', 'code'],
            };

            $query->whereHas(
                $relationAndColumn[0],
                fn ($relationQuery) => $relationQuery->where($relationAndColumn[1], (string) $value)
            );
        }

        if (array_key_exists('active', $filters)) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query
            ->orderBy('notification_type_id')
            ->orderByDesc('version')
            ->get();
    }

    public function createTemplateVersion(array $data, User $actor): NotificationTemplate
    {
        $attributes = Arr::only($data, [
            'notification_type_id',
            'channel_id',
            'language_id',
            'subject',
            'body',
        ]);

        return DB::transaction(function () use ($attributes, $actor): NotificationTemplate {
            $identity = Arr::only($attributes, ['notification_type_id', 'channel_id', 'language_id']);

            NotificationType::query()
                ->whereKey($identity['notification_type_id'])
                ->lockForUpdate()
                ->first();

            $maxVersion = NotificationTemplate::query()
                ->where($identity)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->value('version');

            $nextVersion = ((int) ($maxVersion ?? 0)) + 1;

            $previousActiveTemplates = NotificationTemplate::query()
                ->where($identity)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get(['id', 'version', 'is_active']);

            if ($previousActiveTemplates->isNotEmpty()) {
                NotificationTemplate::query()
                    ->whereKey($previousActiveTemplates->modelKeys())
                    ->update(['is_active' => false]);
            }

            $template = NotificationTemplate::query()->create([
                ...$identity,
                'subject' => $attributes['subject'] ?? null,
                'body' => $attributes['body'],
                'version' => $nextVersion,
                'is_active' => true,
                'updated_by' => $actor->id,
            ]);

            $this->logAdminActivity(
                $actor,
                $template,
                [
                    'old' => [
                        'deactivated_templates' => $previousActiveTemplates
                            ->map(fn ($previous): array => [
                                'id' => $previous->id,
                                'version' => $previous->version,
                                'is_active' => (bool) $previous->is_active,
                            ])
                            ->all(),
                    ],
                    'new' => [
                        'id' => $template->id,
                        ...$identity,
                        'version' => $nextVersion,
                        'is_active' => true,
                    ],
                ],
                'تم إنشاء إصدار جديد لقالب إشعار'
            );

            return $template->load(self::TEMPLATE_RELATIONS);
        });
    }

    public function updateTemplateStatus(
        NotificationTemplate $template,
        bool $isActive,
        User $actor
    ): NotificationTemplate {
        return DB::transaction(function () use ($template, $isActive, $actor): NotificationTemplate {
            $template = NotificationTemplate::query()
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = ['is_active' => (bool) $template->is_active];
            $template->is_active = $isActive;
            $template->updated_by = $actor->id;
            $template->save();

            $this->logAdminActivity(
                $actor,
                $template,
                [
                    'old' => $oldValues,
                    'new' => ['is_active' => (bool) $template->is_active],
                ],
                'تم تعديل حالة قالب إشعار'
            );

            return $template->load(self::TEMPLATE_RELATIONS);
        });
    }

    /**
     * @return array{subject: string|null, body: string}
     */
    public function previewTemplate(NotificationTemplate $template, array $data = []): array
    {
        $subject = $template->subject;
        $body = $template->body;

        foreach ([$subject, $body] as $text) {
            if ($text === null) {
                continue;
            }

            preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $text, $matches);

            foreach ($matches[1] as $variable) {
                if (! Arr::has($data, $variable)) {
                    $data = data_set($data, $variable, '');
                }
            }
        }

        return [
            'subject' => $subject === null ? null : $this->templateRenderer->render($subject, $data),
            'body' => $this->templateRenderer->render($body, $data),
        ];
    }

    private function typeAuditValues(NotificationType $type, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $value = $type->getRawOriginal($field);

            if (is_string($value)) {
                $decodedValue = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decodedValue;
                }
            }

            $values[$field] = $value;
        }

        return $values;
    }

    private function logAdminActivity(User $actor, Model $subject, array $properties, string $description): void
    {
        activity('admin')
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
