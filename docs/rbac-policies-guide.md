# دليل الفريق: الصلاحيات والـ Policies (B4)

> المرجع: `requerments/Solaris_Authorization_Matrix_Wave1.xlsx` (مسودة 2) ونسختها `tests/fixtures/authorization_matrix.csv`.

## الفكرة في سطرين

- **هل يملك الصلاحية؟** يحسبها `AccessResolver` مرة واحدة لكل طلب: أدوار النطاق العام ∪ أدوار النطاق الفعّال، مفلترة بالسياق الفعّال (الصلاحيات الذاتية تسري دائمًا).
- **هل السجل داخل نطاقه؟** يحسمها `ScopeGuard` حسب ورقة «قواعد النطاق» (G عام · C دولة · R منطقة وما تحتها · O مؤسسة).

لا تستخدم `hasRole()` في قرارات التفويض، ولا تنشئ صلاحيات من الكود. المصدر الوحيد هو `RbacCatalog.php` ثم `RoleSeeder`.

## كتابة Policy

```php
use App\Policies\ScopeGuard;

class OrganizationPolicy
{
    // بلا سجل: الصلاحية فقط
    public function viewAny(User $user): Response
    {
        return ScopeGuard::authorize($user, 'organizations.view_any');
    }

    // مع سجل: الصلاحية + النطاق.
    // الوسيط الرابع = صلاحية العرض، ويحدد 403 أو 404:
    //   داخل النطاق                         → سماح
    //   يرى السجل لكن بلا صلاحية التعديل   → 403
    //   يملك الصلاحية والسجل خارج نطاقه     → 404 (لا نكشف وجوده)
    public function update(User $user, Organization $organization): Response
    {
        return ScopeGuard::authorize($user, 'organizations.update', $organization, 'organizations.view');
    }
}
```

`ScopeGuard::authorize` يطبّق حارس MFA تلقائيًا على الصلاحيات `requires_mfa`.

## في الـ Controller

```php
public function update(UpdateOrganizationRequest $request, Organization $organization)
{
    Gate::authorize('update', $organization);   // 403 / 404 حسب القرار
    // ...
}

public function index(Request $request)
{
    Gate::authorize('viewAny', Organization::class);

    // القوائم: فلترة النطاق إلزامية، فإخفاء السجلات من الواجهة لا يكفي
    return OrganizationResource::collection(
        Organization::query()->visibleTo($request->user())->paginate()
    );
}
```

- `visibleTo($user, ?permission)` متاح على `User` و`Organization` و`AccountTypeRequest`. الصلاحية الافتراضية هي `*.view_any`.
- لموارد أخرى استخدم `ScopeGuard::visibleTo($query, $user, 'perm')` أو `ScopeGuard::covers($user, $model, 'perm')`.
- فحص صلاحية بلا Policy: `$user->can('reports.export')` (يمر بحارس MFA وبمنطق super_admin).

## إسناد الأدوار: RoleAssignmentService فقط

```php
app(RoleAssignmentService::class)->assign($actor, $target, 'instructor', AccessScope::forOrganization($org), $reason, $expiresAt);
app(RoleAssignmentService::class)->revoke($actor, $target, 'instructor', $scope, $reason);
app(RoleAssignmentService::class)->canGrant($actor, $role, $scope);   // للدعوات ولمراجعة طلبات نوع الحساب
app(RoleAssignmentService::class)->assignBySystem($user, 'learner'); // التسجيل الذاتي (granted_by = NULL)
```

الخدمة تفرض ما يلي:
- الرتبة أقل تمامًا من رتبة المُسنِد.
- نوع النطاق ضمن الأنواع المسموحة للدور.
- نطاق الإسناد داخل نطاق المُسنِد.
- لا إسناد للنفس.
- لا `super_admin` عبر الـ API.
- قائمة الأدوار المسموح لكل مُسنِد، والعضوية النشطة للإسناد بنطاق مؤسسة.
- سبب الإسناد للأدوار الإدارية.
- حارس MFA.

وبعد كل إسناد أو سحب تسجّل الخدمة في `activity_log` (`rbac` بحساسية `restricted`) وتمسح الكاش. عند الرفض ترمي `RoleAssignmentException`، وتُعرض تلقائيًا بشكل `{message, code}` مع 403 أو 404 أو 422.

## GET /api/v1/me/permissions

```json
{
  "data": {
    "context": { "id": 11, "key": "administration" },
    "scope": { "id": 1, "type": "global", "country_id": null, "region_id": null, "organization_uuid": null },
    "is_super_admin": false,
    "roles": [ { "name": "platform_admin", "rank": 90, "scope_id": 1, "scope_type": "global", "counted": true, "expires_at": null } ],
    "permissions": ["dashboard.view", "profile.view_own", "users.view_any"],
    "mfa_required": ["users.update_status"],
    "mfa": { "mode": "warn", "confirmed": false }
  }
}
```

تستخدم الواجهة `permissions` لإظهار الأزرار فقط. المنع الحقيقي على الـ API.

## MFA (القرار D8)

`SOLARIS_MFA_MODE=warn` (الافتراضي): يسمح بالعملية ويسجّل تحذيرًا في `activity_log` (`security` / `mfa_required`). بعد تنفيذ FA2 يصبح `enforce` فيُرفض الطلب بـ 403 ما لم يكن MFA مؤكَّدًا.

## إضافة صلاحية جديدة

1. أضفها في `RbacCatalog::permissions()` واربطها بالأدوار في `roles()`، وفي `globalOnly()` إن كانت خلية G.
2. حدّث المصفوفة المعتمدة، ثم أعد تصدير `tests/fixtures/authorization_matrix.csv`.
3. شغّل `php artisan db:seed --class="Database\Seeders\Rbac\PermissionSeeder"` ثم `RoleSeeder`.
4. شغّل `php artisan test --filter=AuthorizationMatrixTest`: يجب أن تطابق كل خلية.
