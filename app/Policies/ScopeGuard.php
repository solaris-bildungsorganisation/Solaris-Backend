<?php

namespace App\Policies;

use App\Models\AccountTypeRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScopeGuard
{
    /**
     * Determine whether a model belongs to the user's active context.
     *
     * A 404 is used deliberately so that callers cannot distinguish an
     * inaccessible model from a model that does not exist.
     */
    public static function covers(User $user, Model $model): bool
    {
        self::assertSupportedModel($model);

        $scopeId = self::activeScopeId($user);

        if ($scopeId === null || $model->getAttribute('context_id') === null
            || (string) $model->getAttribute('context_id') !== (string) $scopeId) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /**
     * Restrict a query to models in the user's active context.
     */
    public static function visibleTo(Builder $query, User $user): Builder
    {
        self::assertSupportedModel($query->getModel());

        $scopeId = self::activeScopeId($user);

        return $scopeId === null
            ? $query->whereRaw('1 = 0')
            : $query->where($query->getModel()->qualifyColumn('context_id'), $scopeId);
    }

    private static function activeScopeId(User $user): mixed
    {
        $scopeId = session('active_scope_id');

        if ($scopeId !== null) {
            return $scopeId;
        }

        if (method_exists($user, 'currentContextId')) {
            return $user->currentContextId();
        }

        return $user->currentSession()?->active_context_id;
    }

    private static function assertSupportedModel(Model $model): void
    {
        if (! $model instanceof User
            && ! $model instanceof Organization
            && ! $model instanceof AccountTypeRequest) {
            throw new InvalidArgumentException(
                'ScopeGuard only supports User, Organization, and AccountTypeRequest models.'
            );
        }
    }
}
