<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use App\Policies\ScopeGuard;
use App\Services\Rbac\AccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
class AuditLogController extends Controller
{

    #[OA\Get(
        path: '/v1/admin/audit-logs',
        operationId: 'adminAuditLogsIndex',
        summary: 'عرض سجلات التدقيق',
        description: 'يعرض سجلات النشاط والتدقيق ضمن الصلاحيات والنطاقات الممنوحة للمستخدم مع إمكانية التصفية والترقيم.',
        security: [['bearerAuth' => []]],
        tags: ['Admin - Audit Logs'],
        parameters: [
            new OA\Parameter(
                name: 'log_name',
                description: 'اسم السجل للتصفية',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'default')
            ),
            new OA\Parameter(
                name: 'event',
                description: 'نوع الحدث للتصفية',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'updated')
            ),
            new OA\Parameter(
                name: 'causer',
                description: 'معرّف المتسبب بالحدث (causer_id)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
            new OA\Parameter(
                name: 'subject_type',
                description: 'نوع الكيان المتأثر بالحدث',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'App\\Models\\User')
            ),
            new OA\Parameter(
                name: 'organization',
                description: 'معرّف المنظمة للتصفية',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 1)
            ),
            new OA\Parameter(
                name: 'per_page',
                description: 'عدد السجلات في الصفحة الواحدة (من 1 إلى 100، الافتراضي 20)',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1)
            ),
            new OA\Parameter(
                name: 'page',
                description: 'رقم الصفحة',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم جلب سجلات التدقيق بنجاح',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'current_page', type: 'integer', example: 1),
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'per_page', type: 'integer', example: 20),
                        new OA\Property(property: 'total', type: 'integer', example: 50),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'غير مصرح'
            ),
            new OA\Response(
                response: 403,
                description: 'ليس لديك صلاحية لعرض سجلات التدقيق'
            ),
        ]
    )]
    public function index(Request $request)
    {
        $user = $request->user();
        $access = app(AccessResolver::class)->for($user);

        abort_unless($access->has('audit_log.view'), 403);

        $query = Activity::query();

        // Restricted logs require an additional permission.
        if (! $access->has('audit_log.view_restricted')) {
            $query->where('sensitivity', '!=', 'restricted');
        }
// Apply granted scopes. Fail closed if no scopes are available.
        $scopes = $access->scopesFor('audit_log.view');

        if (empty($scopes) || count($scopes) === 0) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where(function (Builder $q) use ($scopes) {
                $hasValidScope = false;

                foreach ($scopes as $scope) {
                    if ($scope->type === 'global') {
                        $q->orWhereRaw('1 = 1');
                        $hasValidScope = true;
                        break;
                    }

                    if ($scope->type === 'organization') {
                        $q->orWhere('organization_id', $scope->organization_id);
                        $hasValidScope = true;
                    }

                    if ($scope->type === 'country') {
                        $q->orWhereHas('organization', function (Builder $org) use ($scope) {
                            $org->where('country_id', $scope->country_id);
                        });

                        $q->orWhereHasMorph('causer', [User::class], function (Builder $causer) use ($scope) {
                            $causer->where('country_id', $scope->country_id);
                        });

                        $hasValidScope = true;
                    }

                    if ($scope->type === 'region') {
                        $regionIds = ScopeGuard::regionSubtree((int) $scope->region_id);

                        if (! empty($regionIds)) {
                            $q->orWhereHas('organization', function (Builder $org) use ($regionIds) {
                                $org->whereIn('region_id', $regionIds);
                            });

                            $q->orWhereHasMorph('causer', [User::class], function (Builder $causer) use ($regionIds) {
                                $causer->whereIn('region_id', $regionIds);
                            });

                            $hasValidScope = true;
                        }
                    }
                }

                // Fail closed if the provided scopes are not recognized.
                if (! $hasValidScope) {
                    $q->whereRaw('1 = 0');
                }
            });
        }
        // Search filters.
        if ($request->filled('log_name')) {
            $query->where('log_name', $request->input('log_name'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->input('event'));
        }

        if ($request->filled('causer')) {
            $query->where('causer_id', $request->input('causer'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        if ($request->filled('organization')) {
            $query->where('organization_id', $request->input('organization'));
        }


        // Pagination: 1–100 records per page.
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $logs = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Never expose IP address or User-Agent.
        $logs->getCollection()->each(function (Activity $activity) {
            $activity->makeHidden([
                'ip_address',
                'user_agent',
            ]);
        });

        return response()->json($logs);
    }


    #[OA\Get(
        path: '/v1/admin/audit-logs/{activity}',
        tags: ['Admin - Audit Logs'],
        summary: 'عرض تفاصيل سجل التدقيق',
        description: 'يعرض تفاصيل سجل التدقيق بما فيها الخصائص القديمة والجديدة ضمن الصلاحيات والنطاقات الممنوحة للمستخدم.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'activity',
                in: 'path',
                required: true,
                description: 'معرّف سجل التدقيق',
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم جلب تفاصيل سجل التدقيق بنجاح'
            ),
            new OA\Response(
                response: 401,
                description: 'غير مصرح'
            ),
            new OA\Response(
                response: 403,
                description: 'ليس لديك صلاحية أو السجل خارج نطاق الوصول'
            ),
            new OA\Response(
                response: 404,
                description: 'سجل التدقيق غير موجود'
            ),
        ]
    )]

    public function show(Request $request, Activity $activity)
    {
        $user = $request->user();
        $access = app(AccessResolver::class)->for($user);

        abort_unless($access->has('audit_log.view'), 403);

        // Restricted logs require additional permission.
        if ($activity->sensitivity === 'restricted') {
            abort_unless($access->has('audit_log.view_restricted'), 403);
        }

        // Check whether the activity is within the user's granted scopes.
        $scopes = $access->scopesFor('audit_log.view');

        $visible = false;

        foreach ($scopes as $scope) {
            if ($scope->type === 'global') {
                $visible = true;
                break;
            }

            if ($scope->type === 'organization') {
                if ((int) $activity->organization_id === (int) $scope->organization_id) {
                    $visible = true;
                    break;
                }
            }

            if ($scope->type === 'country') {
                $visibleOrganization = $activity->organization()
                    ->where('country_id', $scope->country_id)
                    ->exists();

                $visibleCauser = $activity->causer instanceof User
                    && (int) $activity->causer->country_id === (int) $scope->country_id;

                if ($visibleOrganization || $visibleCauser) {
                    $visible = true;
                    break;
                }
            }

            if ($scope->type === 'region') {
                $regionIds = ScopeGuard::regionSubtree((int) $scope->region_id);

                $visibleOrganization = ! empty($regionIds)
                    && $activity->organization()
                        ->whereIn('region_id', $regionIds)
                        ->exists();

                $visibleCauser = $activity->causer instanceof User
                    && in_array((int) $activity->causer->region_id, $regionIds, true);

                if ($visibleOrganization || $visibleCauser) {
                    $visible = true;
                    break;
                }
            }
        }

        abort_unless($visible, 403);

        // Hide sensitive technical information.
        $activity->makeHidden([
            'ip_address',
            'user_agent',
        ]);

        return response()->json($activity);
    }
}
