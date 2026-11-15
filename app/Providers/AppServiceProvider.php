<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user): ?bool {
            $activeScopeId = session('active_scope_id');

            if ($activeScopeId === null) {
                $activeScopeId = $user->currentSession()?->active_scope_id;
            }

            $isGlobalScope = $activeScopeId === null
                || (string) $activeScopeId === (string) config('solaris.global_scope_id');

            if ($isGlobalScope && $user->hasRole('super_admin')) {
                activity('auth')
                    ->causedBy($user)
                    ->withProperties(['scope' => 'global'])
                    ->log('Super-admin performed global authorization bypass');

                return true;
            }

            return null;
        });
    }
}
