<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** user_contexts — السياقات المتاحة للمستخدم، سياق افتراضي واحد فقط (قيد في القاعدة). */
class UserContext extends Model
{
    /** default_user_key عمود مولّد — لا يُكتب */
    protected $fillable = [
        'user_id', 'context_id', 'status', 'is_default',
        'activated_at', 'deactivated_at', 'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Context::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
