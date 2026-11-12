<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * report_exports — طلب تصدير يُعالَج في Queue.
 * status: queued | processing | completed | failed | expired — format: xlsx | csv | pdf
 */
class ReportExport extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'report_key', 'filters', 'format', 'context_id', 'organization_id',
    ];

    protected $hidden = ['id', 'file_path'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Context::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
