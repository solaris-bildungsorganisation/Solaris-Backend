<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** user_languages — كفاءة المستخدم اللغوية (A1..C2 | native). مستقلة عن لغة الواجهة. */
class UserLanguage extends Model
{
    protected $fillable = ['user_id', 'language_id', 'proficiency', 'is_native', 'source'];

    protected function casts(): array
    {
        return [
            'is_native' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
