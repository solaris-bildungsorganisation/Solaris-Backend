<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** password_histories — hash فقط، لمنع إعادة استخدام كلمات المرور (FR-AUTH-027). */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'password_hash'];

    protected $hidden = ['password_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
