<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** languages — ar (rtl) / en / de. */
class Language extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'code', 'name', 'native_name', 'direction',
        'is_interface', 'is_content', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_interface' => 'boolean',
            'is_content' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    /** الدول التي تعتبر هذه اللغة افتراضية */
    public function countries(): HasMany
    {
        return $this->hasMany(Country::class, 'default_language_id');
    }

    /** المستخدمون الذين يستخدمونها كلغة واجهة */
    public function interfaceUsers(): HasMany
    {
        return $this->hasMany(User::class, 'interface_language_id');
    }

    public function userLanguages(): HasMany
    {
        return $this->hasMany(UserLanguage::class);
    }

    /** المستخدمون الذين يتقنونها (كفاءة لغوية) */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_languages')
            ->withPivot(['id', 'proficiency', 'is_native', 'source', 'verified_at'])
            ->withTimestamps();
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(LanguageRequirement::class);
    }

    public function uiTranslations(): HasMany
    {
        return $this->hasMany(UiTranslation::class);
    }

    public function notificationTemplates(): HasMany
    {
        return $this->hasMany(NotificationTemplate::class);
    }
}
