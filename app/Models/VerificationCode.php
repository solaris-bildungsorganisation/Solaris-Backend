<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** verification_codes — الرمز مخزّن hash فقط. purpose: email_verification | phone_verification | password_reset | mfa_login | contact_change */
class VerificationCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'channel', 'destination', 'purpose', 'code_hash',
        'max_attempts', 'expires_at', 'ip_address',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
