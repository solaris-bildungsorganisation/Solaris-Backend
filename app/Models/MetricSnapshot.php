<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** metric_snapshots — مؤشرات مجمّعة لكل فترة. لا timestamps (computed_at فقط). */
class MetricSnapshot extends Model
{
    public $timestamps = false;

    /** snapshot_key عمود مولّد — لا يُكتب */
    protected $fillable = [
        'metric_key', 'period_type', 'period_start', 'period_end',
        'country_id', 'region_id', 'organization_id',
        'dimensions', 'dimensions_hash', 'value', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'dimensions' => 'array',
            'value' => 'decimal:4',
            'computed_at' => 'datetime',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
