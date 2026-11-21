<?php

namespace App\Services;

use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class NotificationPreferenceService
{
    /**
     * مصفوفة الفئة × القناة مع is_enabled و locked.
     */
    public function matrix(User $user): array
    {
        $channels = NotificationChannel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $types = NotificationType::query()->where('is_active', true)->get();

        $saved = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($p) => $p->category.':'.$p->channel_id);

        $rows = [];

        foreach ($types->groupBy('category') as $category => $categoryTypes) {
            // الفئة مقفلة إذا كل أنواعها إلزامية
            $locked = $categoryTypes->every(fn ($t) => (bool) $t->is_mandatory);

            $rows[] = [
                'category' => $category,
                'locked' => $locked,
                'channels' => $channels->map(function ($channel) use ($saved, $category, $locked) {
                    $pref = $saved->get($category.':'.$channel->id);

                    return [
                        'channel' => $channel->key,
                        'is_enabled' => $locked ? true : ($pref ? (bool) $pref->is_enabled : true),
                    ];
                })->values()->all(),
            ];
        }

        return $rows;
    }

    /**
     * تحديث جماعي. $items = [['category'=>..., 'channel'=>..., 'is_enabled'=>bool], ...]
     */
    public function update(User $user, array $items): void
    {
        $channels = NotificationChannel::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('key');

        $lockedCategories = NotificationType::query()
            ->where('is_active', true)
            ->get()
            ->groupBy('category')
            ->filter(fn ($types) => $types->every(fn ($t) => (bool) $t->is_mandatory))
            ->keys()
            ->all();

        foreach ($items as $item) {
            $channel = $channels->get($item['channel']);

            if (! $channel) {
                throw ValidationException::withMessages([
                    'preferences' => ['قناة غير موجودة أو غير نشطة: '.$item['channel']],
                ]);
            }

            if (! $item['is_enabled'] && in_array($item['category'], $lockedCategories, true)) {
                throw ValidationException::withMessages([
                    'preferences' => ['لا يمكن تعطيل فئة إلزامية: '.$item['category']],
                ]);
            }
        }

        foreach ($items as $item) {
            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'category' => $item['category'],
                    'channel_id' => $channels->get($item['channel'])->id,
                ],
                ['is_enabled' => $item['is_enabled']],
            );
        }
    }
}
