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
        tags: ['Admin - Audit Logs'],
        summary: 'عرض سجلات التدقيق',
        description: 'يعرض سجلات النشاط والتدقيق ضمن الصلاحيات والنطاقات الممنوحة للمستخدم مع إمكانية التصفية والترقيم.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(
                name: 'log_name',
                in: 'query',
                required: false,
                description: 'اسم السجل للتصفية',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'event',
                in: 'query',
                required: false,
                description: 'نوع الحدث للتصفية',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'causer',
                in: 'query',
                required: false,
                description: 'معرّف المتسبب بالحدث (causer_id)',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'subject_type',
                in: 'query',
                required: false,
                description: 'نوع الكيان المتأثر بالحدث',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'organization',
                in: 'query',
                required: false,
                description: 'معرّف المنظمة للتصفية',
                schema: new OA\Schema(type: 'integer')
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                description: 'عدد السجلات في الصفحة الواحدة (من 1 إلى 100، الافتراضي 20)',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)
            ),
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                description: 'رقم الصفحة',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'تم جلب سجلات التدقيق بنجاح'
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
}
