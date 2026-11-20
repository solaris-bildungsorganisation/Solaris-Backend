<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Rbac\MfaGuard;
use Database\Seeders\Rbac\RbacCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * GET /me/permissions — صلاحيات السياق والنطاق الفعّالين، لتُظهر الواجهة الأزرار المناسبة.
 *
 * الواجهة تستخدم هذه القائمة لإظهار/إخفاء العناصر فقط؛ المنع الحقيقي على الـ API عبر Policies
 * (قاعدة المصفوفة 1: إخفاء الزر لا يكفي).
 */
class PermissionController extends Controller
{
    #[OA\Get(
        path: '/v1/me/permissions',
        tags: ['Me'],
        summary: 'صلاحياتي الفعلية في السياق والنطاق الفعّالين',
        description: 'الصلاحيات الفعلية = أدوار النطاق العام ∪ أدوار النطاق الفعّال، مفلترة بالسياق الفعّال (الصلاحيات الذاتية تسري دائمًا). '
            .'mfa_required: الصلاحيات التي تتطلب MFA (في وضع warn تُسمح مع تسجيل تحذير — القرار D8). '
            .'mfa.confirmed = للجلسة تحقق 2FA حديث (step-up خلال 15 دقيقة عبر POST /v1/me/2fa/verify)؛ mfa.enforced = الرفض مفعّل لهذا المستخدم.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'نجاح',
                content: new OA\JsonContent(
                    example: [
                        'data' => [
                            'context' => ['id' => 11, 'key' => 'administration'],
                            'scope' => ['id' => 1, 'type' => 'global', 'country_id' => null, 'region_id' => null, 'organization_uuid' => null],
                            'is_super_admin' => false,
                            'roles' => [
                                ['name' => 'platform_admin', 'rank' => 90, 'scope_id' => 1, 'scope_type' => 'global', 'counted' => true, 'expires_at' => null],
                                ['name' => 'learner', 'rank' => 10, 'scope_id' => 1, 'scope_type' => 'global', 'counted' => false, 'expires_at' => null],
                            ],
                            'permissions' => ['dashboard.view', 'profile.view_own', 'users.update_status', 'users.view', 'users.view_any'],
                            'mfa_required' => ['users.update_status'],
                            'mfa' => ['mode' => 'warn', 'enforced' => false, 'two_factor_enabled' => true, 'confirmed' => false],
                        ],
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        ]
    )]
    public function __invoke(Request $request, MfaGuard $mfa): JsonResponse
    {
        $user = $request->user();
        $access = $user->effectiveAccess();
        $scope = $access->activeScope;
        $permissions = $access->permissions();

        return response()->json([
            'data' => [
                'context' => $access->activeContextId === null ? null : [
                    'id' => $access->activeContextId,
                    'key' => $access->activeContextKey,
                ],
                'scope' => [
                    'id' => $scope->getKey(),
                    'type' => $scope->type,
                    'country_id' => $scope->country_id,
                    'region_id' => $scope->region_id,
                    'organization_uuid' => $scope->organization_id
                        ? Organization::withTrashed()->whereKey($scope->organization_id)->value('uuid')
                        : null,
                ],
                'is_super_admin' => $access->superAdmin,
                'roles' => array_map(fn (array $r) => [
                    'name' => $r['name'],
                    'rank' => $r['rank'],
                    'scope_id' => $r['scope_id'],
                    'scope_type' => $r['scope_type'],
                    'counted' => $r['counted'],
                    'expires_at' => $r['expires_at'],
                ], $access->roles),
                'permissions' => $permissions,
                'mfa_required' => array_values(array_filter($permissions, fn (string $p) => RbacCatalog::requiresMfa($p))),
                'mfa' => [
                    'mode' => $mfa->mode(),
                    'enforced' => $mfa->enforcedFor($user),
                    'two_factor_enabled' => $mfa->enabled($user),
                    'confirmed' => $mfa->satisfied($user),
                ],
            ],
        ]);
    }
}
