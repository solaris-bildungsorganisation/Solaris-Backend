<?php

namespace App\Providers;

use App\Models\AccessScope;
use App\Models\User;
use App\Notifications\Channels\SafeBroadcastChannel;
use App\Notifications\Channels\SmsChannel;
use App\Policies\NotificationPolicy;
use App\Services\Rbac\AccessResolver;
use App\Services\Rbac\MfaGuard;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // $this->app->make(ChannelManager::class)->extend('sms', fn () => $this->app->make(SmsChannel::class));
        // $this->app->make(ChannelManager::class)->extend('broadcast', fn () => $this->app->make(SafeBroadcastChannel::class));
        $this->registerAuthorizationGate();
        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);
        $this->configureRateLimiting();
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

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login-auth', fn (Request $request) => Limit::perMinute(
            config('solaris.rate_limits.login_per_minute')
        )->by($request->ip().'|'.strtolower((string) $request->input('email'))));

        RateLimiter::for('code-auth', fn (Request $request) => Limit::perMinute(
            config('solaris.rate_limits.code_per_minute')
        )->by($request->ip().'|'.strtolower((string) $request->input('email'))));

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(
            config('solaris.rate_limits.register_per_minute')
        )->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            config('solaris.rate_limits.api_per_minute')
        )->by($request->user()?->getKey() ?: $request->ip()));
    }
}
