<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ui_translations — ترجمة لكل (group, key, language). */
class UiTranslation extends Model
{
    protected $fillable = ['group', 'key', 'language_id', 'value', 'updated_by'];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
