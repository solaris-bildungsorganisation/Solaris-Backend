<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * language_requirements — polymorphic: يرتبط لاحقًا بالبرامج/الدورات/الفرص.
 * في المودل الآخر: public function languageRequirements(): MorphMany
 *                  { return $this->morphMany(LanguageRequirement::class, 'requirable'); }
 */
class LanguageRequirement extends Model
{
    protected $fillable = [
        'requirable_type', 'requirable_id', 'language_id', 'min_proficiency', 'is_mandatory', 'purpose',
    ];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean'];
    }

    public function requirable(): MorphTo
    {
        return $this->morphTo();
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
