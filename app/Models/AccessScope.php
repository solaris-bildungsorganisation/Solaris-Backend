<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * access_scopes — "الفريق" في spatie (العمود scope_id في جداول الأدوار).
 * type: global | country | region | organization. الصف id=1 هو النطاق العام.
 */
class AccessScope extends Model
{
    public const TYPE_GLOBAL = 'global';
    public const TYPE_COUNTRY = 'country';
    public const TYPE_REGION = 'region';
    public const TYPE_ORGANIZATION = 'organization';

    /** target_key عمود مولّد (virtual) — لا يُكتب أبدًا */
    protected $fillable = ['type', 'country_id', 'region_id', 'organization_id'];

    public static function global(): self
    {
        return static::query()->findOrFail(config('solaris.global_scope_id'));
    }

    public static function forCountry(Country $country): self
    {
        return static::query()->firstOrCreate(['type' => self::TYPE_COUNTRY, 'country_id' => $country->id]);
    }

    public static function forRegion(Region $region): self
    {
        return static::query()->firstOrCreate([
            'type' => self::TYPE_REGION, 'country_id' => $region->country_id, 'region_id' => $region->id,
        ]);
    }

    public static function forOrganization(Organization $organization): self
    {
        return static::query()->firstOrCreate(['type' => self::TYPE_ORGANIZATION, 'organization_id' => $organization->id]);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** الجلسات التي هذا نطاقها الفعّال */
    public function activeSessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'active_scope_id');
    }

    public function grantedAccountTypeRequests(): HasMany
    {
        return $this->hasMany(AccountTypeRequest::class, 'granted_scope_id');
    }
}
