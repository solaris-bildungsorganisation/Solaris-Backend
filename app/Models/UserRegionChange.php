<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** user_region_changes — status: pending | approved | rejected | applied */
class UserRegionChange extends Model
{
    protected $fillable = [
        'user_id', 'old_country_id', 'old_region_id', 'new_country_id', 'new_region_id',
        'reason', 'requested_by',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oldCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'old_country_id');
    }

    public function oldRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'old_region_id');
    }

    public function newCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'new_country_id');
    }

    public function newRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'new_region_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
