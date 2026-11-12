#!/usr/bin/env bash
# =============================================================================
#  Solaris — Wave 1 scaffolding (Laravel 12)
#  Generates: Enums · Models · Factories · Policies · API Resources ·
#             Form Requests (Store/Update per domain) · Controllers (Api/V1) ·
#             Feature tests · DemoSeeder
#
#  NOT generated here (already delivered in solaris-wave1-db-laravel12.zip):
#    - migrations        → database/migrations (never use `make:model -m` for Wave 1 tables)
#    - reference seeders → database/seeders/{Reference,Rbac,Notifications}
#
#  Run from the project root, after copying the DB package and running `migrate:fresh --seed`.
#  Safe to re-run: `make:*` skips files that already exist (it never overwrites without --force).
# =============================================================================
set -euo pipefail

[[ -f artisan ]] || { echo "Run this from the Laravel project root (artisan not found)."; exit 1; }
php artisan --version | grep -q "Laravel Framework 12\." || { echo "Laravel 12 expected."; exit 1; }

a() { echo "  › $*"; php artisan "$@" --no-interaction >/dev/null; }
section() { echo; echo "== $1"; }

# -----------------------------------------------------------------------------
section "0. Enums (statuses are strings in DB, PHP enums in code)"
# -----------------------------------------------------------------------------
for e in UserStatus ScopeType Sensitivity AccountTypeRequestStatus \
         OrganizationType OrganizationStatus MembershipType MembershipStatus InvitationStatus \
         LanguageProficiency RegionChangeStatus \
         NotificationCategory DeliveryStatus \
         ReportExportStatus ReportFormat SuccessStoryStatus ConsentStatus; do
  a make:enum "$e" --string
done

# -----------------------------------------------------------------------------
section "1. Models (+ factory where tests need fake data) — NO migrations"
# -----------------------------------------------------------------------------
# Identity
a make:model PasswordHistory
a make:model AccountStatusHistory
# RBAC (Role/Permission extend spatie — written below, not generated)
a make:model Context
a make:model AccessScope -f
a make:model UserContext -f
a make:model AccountTypeRequest -f
# Sessions & security
a make:model UserSession -f
a make:model VerificationCode
a make:model AuthEvent
# Geo
a make:model Country -f
a make:model Region -f
a make:model RegionAccessPolicy -f      # table = region_policies (set $table, see post-steps)
a make:model UserRegionChange -f
# I18N
a make:model Language -f
a make:model UserLanguage -f
a make:model LanguageRequirement
a make:model UiTranslation -f
# ORG
a make:model Organization -f
a make:model OrganizationMembership -f
a make:model OrganizationInvitation -f
# COM
a make:model NotificationChannel
a make:model NotificationType
a make:model NotificationTemplate -f
a make:model NotificationPreference
a make:model NotificationDelivery
# ADMIN
a make:model MetricSnapshot
a make:model ReportExport -f
a make:model SuccessStory -f

# spatie models with our extra columns/casts (config/permission.php → models.role / models.permission)
write_if_missing() { [[ -f "$1" ]] && echo "  · exists: $1" || { cat > "$1"; echo "  › wrote $1"; }; }
write_if_missing app/Models/Role.php <<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected function casts(): array
    {
        return [
            'display_name' => 'array',
            'description' => 'array',
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
}
PHP
write_if_missing app/Models/Permission.php <<'PHP'
<?php

namespace App\Models;

use App\Enums\Sensitivity;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected function casts(): array
    {
        return [
            'display_name' => 'array',
            'sensitivity' => Sensitivity::class,
            'requires_mfa' => 'boolean',
        ];
    }
}
PHP

# -----------------------------------------------------------------------------
section "2. Policies (flat app/Policies so Laravel auto-discovers them)"
# -----------------------------------------------------------------------------
for m in User AccountTypeRequest UserSession Country Region RegionAccessPolicy UserRegionChange \
         Language UserLanguage UiTranslation Organization OrganizationMembership OrganizationInvitation \
         NotificationType NotificationTemplate NotificationDelivery ReportExport SuccessStory; do
  a make:policy "${m}Policy" --model="$m"
done

# -----------------------------------------------------------------------------
section "3. API Resources (never return models directly)"
# -----------------------------------------------------------------------------
for m in User UserSession Context Role AccountTypeRequest Country Region RegionAccessPolicy UserRegionChange \
         Language UserLanguage UiTranslation Organization OrganizationMembership OrganizationInvitation \
         Notification NotificationType NotificationTemplate NotificationDelivery \
         ActivityLog MetricSnapshot ReportExport SuccessStory; do
  a make:resource "${m}Resource"
done

# -----------------------------------------------------------------------------
section "4. Form Requests — one folder per domain; Store + Update for every writable resource"
# -----------------------------------------------------------------------------
# Auth (actions, not CRUD)
for r in RegisterRequest LoginRequest SendVerificationCodeRequest ConfirmVerificationCodeRequest \
         ForgotPasswordRequest ResetPasswordRequest SwitchContextRequest; do
  a make:request "Auth/$r"
done
# Me (the signed-in user's own data)
for r in UpdateProfileRequest ChangePasswordRequest UpdateInterfaceLanguageRequest \
         StoreUserLanguageRequest UpdateUserLanguageRequest \
         SubmitAccountTypeRequest StoreRegionChangeRequest UpdateNotificationPreferencesRequest; do
  a make:request "Me/$r"
done
# Admin
for r in User/StoreUserRequest User/UpdateUserRequest User/UpdateUserStatusRequest User/AssignRoleRequest \
         AccountType/ReviewAccountTypeRequest Geo/ReviewRegionChangeRequest \
         Geo/StoreCountryRequest Geo/UpdateCountryRequest Geo/StoreRegionRequest Geo/UpdateRegionRequest \
         Geo/StoreRegionAccessPolicyRequest Geo/UpdateRegionAccessPolicyRequest \
         I18n/StoreLanguageRequest I18n/UpdateLanguageRequest I18n/StoreTranslationRequest I18n/UpdateTranslationRequest \
         Org/UpdateOrganizationStatusRequest \
         Content/StoreSuccessStoryRequest Content/UpdateSuccessStoryRequest \
         Reports/StoreReportExportRequest \
         Notifications/UpdateNotificationTypeRequest \
         Notifications/StoreNotificationTemplateRequest Notifications/UpdateNotificationTemplateRequest; do
  a make:request "Admin/$r"
done
# Organization (scoped to an organization)
for r in StoreOrganizationRequest UpdateOrganizationRequest \
         StoreOrganizationInvitationRequest UpdateOrganizationMemberRequest; do
  a make:request "Org/$r"
done

# -----------------------------------------------------------------------------
section "5. Controllers — app/Http/Controllers/Api/V1/<Area>"
# -----------------------------------------------------------------------------
C=Api/V1
# Auth — single-action controllers (one endpoint = one class)
for c in RegisterController LoginController LogoutController TokenRefreshController ContextSwitchController; do
  a make:controller "$C/Auth/$c" --invokable
done
a make:controller "$C/Auth/VerificationController"       # send(), confirm()
a make:controller "$C/Auth/PasswordResetController"      # forgot(), reset()
# Public lookups
for c in CountryController RegionController LanguageController; do
  a make:controller "$C/Meta/$c"                          # index() only
done
# Me
a make:controller "$C/Me/ProfileController"              # show(), update()
a make:controller "$C/Me/PasswordController" --invokable
a make:controller "$C/Me/InterfaceLanguageController" --invokable
a make:controller "$C/Me/SessionController"              # index(), destroy(), destroyOthers()
a make:controller "$C/Me/ContextController"              # index()
a make:controller "$C/Me/AccountTypeRequestController"   # index(), store()
a make:controller "$C/Me/RegionChangeRequestController"  # store()
a make:controller "$C/Me/LanguageController" --api --model=UserLanguage
a make:controller "$C/Me/NotificationController"         # index(), unreadCount(), markRead(), markAllRead()
a make:controller "$C/Me/NotificationPreferenceController" # index(), update()
# Admin — CRUD resources
a make:controller "$C/Admin/UserController" --api --model=User
a make:controller "$C/Admin/CountryController" --api --model=Country
a make:controller "$C/Admin/RegionController" --api --model=Region
a make:controller "$C/Admin/RegionAccessPolicyController" --api --model=RegionAccessPolicy
a make:controller "$C/Admin/LanguageController" --api --model=Language
a make:controller "$C/Admin/TranslationController" --api --model=UiTranslation
a make:controller "$C/Admin/SuccessStoryController" --api --model=SuccessStory
a make:controller "$C/Admin/NotificationTemplateController" --api --model=NotificationTemplate
# Admin — actions
for c in UserStatusController UserRestoreController OrganizationStatusController \
         SuccessStoryPublishController DashboardController; do
  a make:controller "$C/Admin/$c" --invokable
done
a make:controller "$C/Admin/UserRoleController"            # store(), destroy()
a make:controller "$C/Admin/AccountTypeRequestController"  # index(), show(), update() = review
a make:controller "$C/Admin/RegionChangeRequestController" # index(), update() = review
a make:controller "$C/Admin/MetricController"              # show(group)
a make:controller "$C/Admin/ReportController"              # show(key)
a make:controller "$C/Admin/ReportExportController"        # store(), show()
a make:controller "$C/Admin/AuditLogController"            # index(), show()
a make:controller "$C/Admin/NotificationTypeController"    # index(), update()
a make:controller "$C/Admin/NotificationDeliveryController" # index()
# Organization
a make:controller "$C/Org/OrganizationController" --api --model=Organization
a make:controller "$C/Org/OrganizationMemberController"     # index(), update(), destroy()
a make:controller "$C/Org/OrganizationInvitationController" # store()
a make:controller "$C/Org/InvitationAcceptController" --invokable
a make:controller "$C/Org/OrganizationStatsController" --invokable

# -----------------------------------------------------------------------------
section "6. Feature tests — one per controller area (DoD: 80% coverage, allow + deny per permission)"
# -----------------------------------------------------------------------------
for t in Auth/RegisterTest Auth/LoginTest Auth/LogoutTest Auth/TokenRefreshTest Auth/ContextSwitchTest \
         Auth/VerificationTest Auth/PasswordResetTest \
         Me/ProfileTest Me/PasswordTest Me/SessionTest Me/AccountTypeRequestTest Me/LanguageTest Me/NotificationTest \
         Admin/UserTest Admin/UserStatusTest Admin/UserRoleTest Admin/AccountTypeRequestReviewTest \
         Admin/GeoTest Admin/I18nTest Admin/SuccessStoryTest Admin/ReportExportTest Admin/AuditLogTest \
         Admin/NotificationTemplateTest \
         Org/OrganizationTest Org/OrganizationMemberTest Org/OrganizationInvitationTest \
         Security/AuthorizationMatrixTest Security/ContextIsolationTest Security/PrivilegeEscalationTest; do
  a make:test "Api/V1/$t"
done

# -----------------------------------------------------------------------------
section "7. Demo data (local/staging only — never called from DatabaseSeeder)"
# -----------------------------------------------------------------------------
a make:seeder DemoSeeder

echo
echo "Done. Post-steps (manual, once):"
cat <<'TXT'
  1. app/Models/User.php: use HasRoles, HasApiTokens, SoftDeletes; casts for status (UserStatus) and encrypted 2FA fields.
  2. config/permission.php: models.role => App\Models\Role::class, models.permission => App\Models\Permission::class
  3. app/Models/RegionAccessPolicy.php: protected $table = 'region_policies';
     app/Models/UserSession.php: use HasUuids;  (uuid primary key)
  4. routes/api.php: Route::prefix('v1')->middleware('auth:sanctum')->group(...)  (+ scope/context middleware from task S-06)
  5. php artisan test
TXT
