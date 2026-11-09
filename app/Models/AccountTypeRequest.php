<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * account_type_requests — طلب المستخدم لدور يحتاج موافقة.
 * status: pending | under_review | approved | rejected | cancelled
 */
class AccountTypeRequest extends Model
{
    use HasUuids;

    /** حقول المراجعة (status, reviewed_by, reviewed_at, granted_scope_id) تُضبط من خدمة المراجعة فقط */
    protected $fillable = [
        'user_id', 'requested_role_id', 'organization_id', 'region_id', 'purpose', 'attachments',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'reviewed_at' => 'datetime',
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

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ['pending', 'under_review']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'requested_role_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function grantedScope(): BelongsTo
    {
        return $this->belongsTo(AccessScope::class, 'granted_scope_id');
    }
}
