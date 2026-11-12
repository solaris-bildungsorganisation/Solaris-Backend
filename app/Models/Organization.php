<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * organizations — الحسابات المؤسسية.
 * type: educational | training_center | ngo | employer | donor | government | partner | other
 * status: pending | active | suspended | archived
 */
class Organization extends Model
{
    use HasTranslations, HasUuids, SoftDeletes;

    public array $translatable = ['name', 'description'];

    /** status و verified_* تُضبط من خدمة الإدارة فقط */
    protected $fillable = [
        'name', 'slug', 'type', 'country_id', 'region_id', 'registration_number',
        'email', 'phone', 'website', 'address', 'logo_path', 'description', 'settings',
        'external_source', 'external_ref', 'created_by',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['id', 'membership_type', 'status', 'title', 'joined_at', 'left_at', 'added_by'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /** نطاق الوصول الخاص بالمؤسسة — يُستخدم مع setPermissionsTeamId() */
    public function accessScope(): HasOne
    {
        return $this->hasOne(AccessScope::class)->where('type', 'organization');
    }

    public function accountTypeRequests(): HasMany
    {
        return $this->hasMany(AccountTypeRequest::class);
    }

    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    public function successStories(): HasMany
    {
        return $this->hasMany(SuccessStory::class);
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }
}
