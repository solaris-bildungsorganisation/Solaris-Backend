<?php

namespace App\Providers;

use App\Models\AccessScope;
use App\Models\User;
use App\Services\Rbac\AccessResolver;
use App\Services\Rbac\MfaGuard;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // نسخة واحدة لكل طلب (scoped: تُفرَّغ بين الطلبات في Octane والـ Queue)
        $this->app->scoped(AccessResolver::class);
        $this->app->scoped(MfaGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthorizationGate();
    }

    /**
     * سلسلة التفويض الموحّدة (B4). تحل محل فحص spatie الافتراضي (register_permission_check_method = false).
     *
     * لكل ability هي اسم صلاحية من الكتالوج (مثل users.update_status):
     *   1. حارس MFA (D8)                         → false يوقف العملية في وضع enforce
     *   2. super_admin في النطاق العام فقط        → true + تسجيل في activity_log (log_name = rbac)
     *   3. الصلاحيات الفعلية (AccessResolver)     → true أو null (= رفض لعدم وجود Gate معرّف)
     *
     * abilities الخاصة بالـ Policies (view, update, review ...) لا تُتجاوز هنا حتى لـ super_admin:
     * الـ Policy تفرض القواعد الصلبة (لا إسناد للنفس، لا مراجعة ذاتية...) ثم تسمح عبر صلاحياته ونطاقه العام.
     */
    private function registerAuthorizationGate(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! $user instanceof User || ! RbacCatalog::isPermission($ability)) {
                return null;
            }

            if (! app(MfaGuard::class)->allows($user, $ability)) {
                return false;
            }

            $resolver = app(AccessResolver::class);
            $access = $resolver->for($user);

            if ($access->superAdmin && $access->activeScope->type === AccessScope::TYPE_GLOBAL) {
                if ($resolver->firstTime("super_admin:{$user->getKey()}:{$ability}")) {
                    activity('rbac')
                        ->causedBy($user)
                        ->tap(function ($activity) use ($ability, $access) {
                            $activity->context_id = $access->activeContextId;
                            $activity->sensitivity = RbacCatalog::sensitivity($ability) === 'restricted' ? 'restricted' : 'sensitive';
                            $activity->ip_address = request()?->ip();
                            $activity->user_agent = request()?->userAgent();
                        })
                        ->withProperties(['ability' => $ability, 'scope' => 'global'])
                        ->event('super_admin_bypass')
                        ->log('Super-admin performed global authorization bypass');
                }

                return true;
            }

            return $access->has($ability) ? true : null;
        });
    }
}
