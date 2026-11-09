<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** account_status_histories — كل تغيير في users.status. */
class AccountStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'from_status', 'to_status', 'reason', 'changed_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** null = النظام */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
