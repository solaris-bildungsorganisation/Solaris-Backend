<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** contexts — السياق الوظيفي (learning, teaching, administration ...). الكتالوج يُدار من ContextSeeder. */
class Context extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = ['key', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function userContexts(): HasMany
    {
        return $this->hasMany(UserContext::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_contexts')
            ->withPivot(['id', 'status', 'is_default', 'activated_at', 'deactivated_at', 'granted_by'])
            ->withTimestamps();
    }
}
