<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Translatable\HasTranslations;

/**
 * roles — جدول spatie + الأعمدة التي أضافها migration 000007.
 * سجّله في config/permission.php → models.role.
 * لا تُنشأ الأدوار من الكود: المصدر الوحيد RbacCatalog + RoleSeeder.
 */
class Role extends SpatieRole
{
    use HasTranslations;

    public array $translatable = ['display_name', 'description'];

    protected function casts(): array
    {
        return [
            'allowed_scope_types' => 'array',
            'is_administrative' => 'boolean',
            'is_self_registrable' => 'boolean',
            'requires_approval' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'rank' => 'integer',
        ];
    }

    public function context(): BelongsTo
    {
        return $this->belongsTo(Context::class);
    }

    public function accountTypeRequests(): HasMany
    {
        return $this->hasMany(AccountTypeRequest::class, 'requested_role_id');
    }

    public function organizationInvitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function allowsScope(string $type): bool
    {
        return in_array($type, $this->allowed_scope_types ?? [], true);
    }
}
