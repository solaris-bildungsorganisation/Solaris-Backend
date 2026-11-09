<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Translatable\HasTranslations;

/**
 * permissions — جدول spatie + resource/action/display_name/sensitivity/requires_mfa (migration 000007).
 * سجّله في config/permission.php → models.permission.
 */
class Permission extends SpatiePermission
{
    use HasTranslations;

    public array $translatable = ['display_name'];

    protected function casts(): array
    {
        return [
            'requires_mfa' => 'boolean',
        ];
    }
}
