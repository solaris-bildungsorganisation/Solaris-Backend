<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

/** countries — policy_group: syria | stable_market | standard. */
class Country extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'iso2', 'iso3', 'name', 'phone_code',
        'default_language_id', 'policy_group', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function defaultLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'default_language_id');
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }

    /** المحافظات/الولايات فقط (المستوى الأول) */
    public function topRegions(): HasMany
    {
        return $this->hasMany(Region::class)->whereNull('parent_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function regionPolicies(): HasMany
    {
        return $this->hasMany(RegionAccessPolicy::class);
    }

    /** نطاق الوصول من نوع country لهذه الدولة (إن أُنشئ) */
    public function accessScope(): HasOne
    {
        return $this->hasOne(AccessScope::class)->where('type', 'country');
    }
}
