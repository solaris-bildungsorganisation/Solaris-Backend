<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/** user_sessions — المفتاح الأساسي uuid. كل جلسة مرتبطة بتوكن Sanctum وسياق ونطاق فعّالين. */
class UserSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'personal_access_token_id', 'active_context_id', 'active_scope_id',
        'device_name', 'platform', 'ip_address', 'user_agent',
        'last_activity_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function activeContext(): BelongsTo
    {
        return $this->belongsTo(Context::class, 'active_context_id');
    }

    public function activeScope(): BelongsTo
    {
        return $this->belongsTo(AccessScope::class, 'active_scope_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** auth_events.session_id — ربط منطقي بدون FK */
    public function authEvents(): HasMany
    {
        return $this->hasMany(AuthEvent::class, 'session_id');
    }
}
