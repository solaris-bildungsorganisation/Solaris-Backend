<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Policies\ScopeGuard;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\Rbac\AccessResolver;
use App\Models\AccessScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementService
{

    public function listUsers(User $admin, array $filters = []): LengthAwarePaginator
    {
        $query = User::query()
            ->visibleTo($admin)
            ->with([
                'interfaceLanguage',
                'country',
                'region',
                'userContexts.context',
                'memberships.organization',
                'roles.context',
            ]);

        // البحث بالبريد أو الهاتف
        foreach (['email', 'phone'] as $field) {
            if (!empty($filters[$field])) {
                $query->where(
                    $field,
                    'like',
                    '%' . $filters[$field] . '%'
                );
            }
        }

        // البحث بالاسم
        if (!empty($filters['name'])) {
            $name = $filters['name'];

            $query->where(function ($q) use ($name) {
                $q->where('first_name', 'like', "%{$name}%")
                    ->orWhere('last_name', 'like', "%{$name}%")
                    ->orWhere('display_name', 'like', "%{$name}%");
            });
        }

        // فلترة الحالة
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // فلترة الدولة والمنطقة
        foreach (['country_id', 'region_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $perPage = max(1, min((int)($filters['per_page'] ?? 15), 100));


        // فلترة حسب الدور
        if (!empty($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

// فلترة حسب السياق
        if (!empty($filters['context'])) {
            $query->whereHas('userContexts.context', function ($q) use ($filters) {
                $q->where('key', $filters['context']);
            });
        }

// فلترة حسب المؤسسة
        if (!empty($filters['organization'])) {
            $query->whereHas('memberships.organization', function ($q) use ($filters) {
                $q->where('uuid', $filters['organization']);
            });
        }

// لازم يكون خارج جميع شروط الفلترة
        return $query->latest()->paginate($perPage);
    }
    public function getUserDetails(User $admin, User $user): User
    {
        return User::query()
            ->visibleTo($admin)
            ->with([
                'interfaceLanguage',
                'country',
                'region',
                'userContexts.context',
                'memberships.organization',
                'roles.context',
                'statusHistories',
                'passwordHistories',
            ])
            ->findOrFail($user->id);
    }

    public function createUser(User $admin, array $data): User
    {
        return DB::transaction(function () use ($admin, $data) {

            $access = app(AccessResolver::class)->for($admin);
            $scopes = $access->scopesFor('users.create');

            if (empty($scopes)) {
                throw new AuthorizationException('ليس لديك صلاحية إنشاء مستخدمين.');
            }

            $organization = null;

            if (!empty($data['organization_id'])) {
                $organization = Organization::query()
                    ->where('uuid', $data['organization_id'])
                    ->where('status', 'active')
                    ->firstOrFail();

                $authorizedOrganization = collect($scopes)->contains(
                    fn ($scope) =>
                        $scope->type === AccessScope::TYPE_GLOBAL
                        || (
                            $scope->type === AccessScope::TYPE_ORGANIZATION
                            && (int) $scope->organization_id === (int) $organization->id
                        )
                );

                if (!$authorizedOrganization) {
                    throw new AuthorizationException(
                        'ليس لديك صلاحية إضافة أعضاء إلى هذه المؤسسة.'
                    );
                }
            }

            $user = new User();

            $user->fill([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'display_name' => $data['display_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make(Str::random(40)),
                'country_id' => $data['country_id'] ?? null,
                'region_id' => $data['region_id'] ?? null,
                'interface_language_id' => $data['interface_language_id'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'status' => 'pending_verification',
                'must_change_password' => true,
            ]);

            $user->created_by = $admin->id;
            $user->save();


            // إنشاء عضوية المؤسسة أولاً حتى يمكن التحقق من نطاقها.
            if ($organization) {
                OrganizationMembership::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'membership_type' => $data['membership_type'] ?? 'learner',
                    'status' => 'active',
                    'joined_at' => now(),
                    'added_by' => $admin->id,
                ]);
            }

// التحقق من نطاق المستخدم بعد إنشاء العضوية.
            $withinScope = collect($scopes)->contains(
                fn ($scope) =>
                    $scope->type === AccessScope::TYPE_GLOBAL
                    || ScopeGuard::scopeCovers($scope, $user)
            );

            if (!$withinScope) {
                throw new AuthorizationException(
                    'المستخدم الجديد خارج نطاق صلاحياتك.'
                );
            }
            app(\App\Services\Rbac\RoleAssignmentService::class)
                ->assignBySystem(
                    $user,
                    'learner',
                    $organization
                        ? AccessScope::forOrganization($organization)
                        : AccessScope::global()
                );

            activity('admin.users')
                ->causedBy($admin)
                ->performedOn($user)
                ->withProperties([
                    'old' => [],
                    'new' => [
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'status' => $user->status,
                        'role' => 'learner',
                        'organization' => $organization?->uuid,
                        'membership_type' => $organization
                            ? ($data['membership_type'] ?? 'learner')
                            : null,
                    ],
                ])
                ->log('تم إنشاء مستخدم جديد');

            return $user;
        });
    }
        public function updateUser(User $user, array $data): User
    {
        $oldValues = $user->only([
            'first_name',
            'last_name',
            'display_name',
            'email',
            'phone',
            'country_id',
            'region_id',
            'interface_language_id',
            'timezone',
        ]);

        $user->fill($data);
        $user->save();

        $newValues = $user->only(array_keys($oldValues));

        activity('admin.users')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties([
                'old' => $oldValues,
                'new' => $newValues,
            ])
            ->log('تم تعديل بيانات مستخدم');

        return $user->refresh();
    }
    }
