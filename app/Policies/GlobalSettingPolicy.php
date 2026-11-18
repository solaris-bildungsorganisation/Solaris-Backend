<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * أساس مشترك لإعدادات المنصة العامة (دول، مناطق، لغات، ترجمات، أنواع وقوالب الإشعارات).
 * خلية "G" في المصفوفة: الصلاحية لا تُحسب إلا من إسناد في النطاق العام (RbacCatalog::globalOnly)،
 * لذلك مسؤول بنطاق دولة/منطقة يُرفض تلقائيًا (الفروقات #7 — D7).
 */
abstract class GlobalSettingPolicy
{
    /** اسم الصلاحية من المصفوفة، مثل countries.manage */
    abstract protected function permission(): string;

    /** هل القراءة عامة لأي مستخدم مسجَّل؟ (بيانات مرجعية تعرضها الواجهة) */
    protected function publicRead(): bool
    {
        return false;
    }

    public function viewAny(User $user): Response
    {
        return $this->publicRead() ? Response::allow() : $this->manage($user);
    }

    public function view(User $user, Model $model): Response
    {
        return $this->viewAny($user);
    }

    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, Model $model): Response
    {
        return $this->manage($user);
    }

    public function delete(User $user, Model $model): Response
    {
        return $this->manage($user);
    }

    public function restore(User $user, Model $model): Response
    {
        return $this->manage($user);
    }

    public function forceDelete(User $user, Model $model): Response
    {
        return Response::deny('الحذف النهائي لإعدادات المنصة غير مسموح.');
    }

    protected function manage(User $user): Response
    {
        return ScopeGuard::authorize($user, $this->permission());
    }
}
