<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * success_stories — لا نشر بدون موافقة (قيد في القاعدة).
 * status: draft | in_review | published | archived — consent_status: not_required | pending | granted | withdrawn
 */
class SuccessStory extends Model
{
    use HasTranslations, HasUuids, SoftDeletes;

    public array $translatable = ['title', 'summary', 'body'];

    /** status/published_at/consent_* تُضبط من خدمات النشر والموافقة */
    protected $fillable = [
        'title', 'summary', 'body', 'subject_user_id', 'organization_id',
        'country_id', 'region_id', 'created_by',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'consent_recorded_at' => 'datetime',
            'published_at' => 'datetime',
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

    public function scopePublished(Builder $query): void
    {
        $query->where('status', 'published')->whereNotNull('published_at');
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function consentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_recorded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
