<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** auth_events — سجل أحداث الدخول والأمان (إضافة فقط). */
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'session_id', 'event', 'identifier_hash', 'ip_address', 'user_agent', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ربط منطقي (لا FK في القاعدة) */
    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }
}
