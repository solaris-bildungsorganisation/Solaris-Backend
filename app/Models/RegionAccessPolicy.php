<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * region_policies — سياسة لكل مفتاح + دولة/منطقة.
 * country_id = null و region_id = null ⇒ القيمة الافتراضية العامة.
 */
class RegionAccessPolicy extends Model
{
    protected $table = 'region_policies';

    /** scope_key عمود مولّد — لا يُكتب */
    protected $fillable = [
        'policy_key', 'country_id', 'region_id', 'value',
        'is_active', 'effective_from', 'effective_to', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    /** السياسات السارية الآن */
    public function scopeEffective(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
