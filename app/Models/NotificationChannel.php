<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** notification_channels — in_app | email | sms | push | whatsapp. الكتالوج من NotificationCatalogSeeder. */
class NotificationChannel extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'key', 'name', 'driver', 'supports_sensitive', 'is_active', 'config', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'supports_sensitive' => 'boolean',
            'is_active' => 'boolean',
            'config' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class, 'channel_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class, 'channel_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'channel_id');
    }
}
