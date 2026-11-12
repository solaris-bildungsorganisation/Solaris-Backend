<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * organization_memberships — عضوية واحدة لكل (organization, user).
 * status: pending | active | suspended | left | removed
 */
class OrganizationMembership extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'membership_type', 'status', 'title',
        'joined_at', 'left_at', 'added_by',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
