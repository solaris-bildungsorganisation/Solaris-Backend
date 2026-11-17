<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Policies\ScopeGuard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query scope موحّد لفلترة القوائم حسب نطاق الإسناد (ورقة "قواعد النطاق").
 *
 *   User::query()->visibleTo($admin)->paginate();                      // users.view_any
 *   Organization::query()->visibleTo($user, 'organization_reports.view');
 */
trait VisibleWithinScope
{
    public function scopeVisibleTo(Builder $query, User $user, ?string $permission = null): Builder
    {
        return ScopeGuard::visibleTo($query, $user, $permission);
    }
}
