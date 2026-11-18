<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\AccessScope;
use App\Models\Organization;
use App\Models\User;
use App\Services\Rbac\AccessResolver;
use App\Services\Rbac\MfaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Spatie\Permission\PermissionRegistrar;

/**
 * اختيار النطاق الفعّال (ورقة B4): GET /me/scopes و POST /me/scope.
 * القيمة تُحفظ في user_sessions.active_scope_id ويقرأها ResolveAccessScope و AccessResolver في كل طلب.
 */
class ScopeController extends Controller
{
    #[OA\Get(
        path: '/v1/me/scopes',
        tags: ['Me'],
        summary: 'النطاقات التي أملك فيها أدوارًا (للاختيار منها)',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'قائمة النطاقات؛ النطاق العام دائمًا موجود، و active يحدد الفعّال حاليًا'),
            new OA\Response(response: 401, description: 'التوكن مفقود أو غير صالح'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeId = $user->effectiveAccess()->activeScope->getKey();

        $activeContext = $user->effectiveAccess()->activeContextId;

        return response()->json([
            'data' => array_map(fn (array $s) => [...$s, 'active' => $s['id'] === $activeId], $this->availableScopes($user)),
            'meta' => [
                'contexts' => array_map(fn (array $c) => [...$c, 'active' => $c['id'] === $activeContext], $this->availableContexts($user)),
            ],
        ]);
    }

    #[OA\Post(
        path: '/v1/me/scope',
        tags: ['Me'],
        summary: 'تفعيل نطاق للجلسة الحالية',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['scope_id'], properties: [
                new OA\Property(property: 'scope_id', type: 'integer', example: 4),
                new OA\Property(property: 'context', type: 'string', nullable: true, example: 'organization',
                    description: 'اختياري: تفعيل سياق من سياقاتي النشطة مع النطاق (دمج مع تبديل السياق — ورقة B4)'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم التفعيل؛ الاستجابة = صلاحياتي في النطاق الجديد (مثل GET /me/permissions)'),
            new OA\Response(response: 409, description: 'التوكن غير مرتبط بجلسة'),
            new OA\Response(response: 422, description: 'نطاق أو سياق غير متاح لك'),
        ]
    )]
    public function store(Request $request, AccessResolver $resolver, MfaGuard $mfa): JsonResponse
    {
        $data = $request->validate([
            'scope_id' => ['required', 'integer'],
            'context' => ['nullable', 'string', 'max:50'],
        ]);
        $user = $request->user();

        $allowed = array_column($this->availableScopes($user), 'id');
        if (! in_array((int) $data['scope_id'], $allowed, true)) {
            return response()->json([
                'message' => 'هذا النطاق غير متاح لك.',
                'errors' => ['scope_id' => ['لا تملك أي دور فعّال في هذا النطاق.']],
            ], 422);
        }

        $contextId = null;
        if (! empty($data['context'])) {
            $contextId = collect($this->availableContexts($user))->firstWhere('key', $data['context'])['id'] ?? null;
            if ($contextId === null) {
                return response()->json([
                    'message' => 'هذا السياق غير متاح لك.',
                    'errors' => ['context' => ['لا تملك هذا السياق أو أنه غير نشط.']],
                ], 422);
            }
        }

        $session = $user->currentSession();
        if (! $session) {
            return response()->json(['message' => 'التوكن الحالي غير مرتبط بجلسة. سجّل الدخول من جديد.'], 409);
        }

        $from = $session->active_scope_id;
        $session->forceFill(array_filter([
            'active_scope_id' => (int) $data['scope_id'],
            'active_context_id' => $contextId,
        ], fn ($v) => $v !== null))->save();
        $resolver->flush();
        app(PermissionRegistrar::class)->setPermissionsTeamId((int) $data['scope_id']);

        activity('auth')
            ->causedBy($user)
            ->tap(function ($activity) use ($request, $session) {
                $activity->session_id = $session->getKey();
                $activity->ip_address = $request->ip();
                $activity->user_agent = $request->userAgent();
            })
            ->withProperties(['from_scope_id' => $from, 'to_scope_id' => (int) $data['scope_id'], 'context_id' => $contextId])
            ->event('scope_switched')
            ->log('Active scope switched');

        return app(PermissionController::class)($request, $mfa);
    }

    /** سياقات المستخدم النشطة (user_contexts). */
    private function availableContexts(User $user): array
    {
        return DB::table('user_contexts as uc')
            ->join('contexts as c', 'c.id', '=', 'uc.context_id')
            ->where('uc.user_id', $user->getKey())
            ->where('uc.status', 'active')
            ->where('c.is_active', true)
            ->orderBy('c.sort_order')
            ->get(['c.id', 'c.key', 'uc.is_default'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'key' => $c->key, 'is_default' => (bool) $c->is_default])
            ->all();
    }

    /** النطاق العام + كل نطاق للمستخدم فيه إسناد غير منتهٍ (مؤسسات نشطة فقط). */
    private function availableScopes(User $user): array
    {
        $t = config('permission.table_names');
        $globalId = (int) config('solaris.global_scope_id');

        $rows = DB::table($t['model_has_roles'].' as mhr')
            ->join($t['roles'].' as r', 'r.id', '=', 'mhr.role_id')
            ->join('access_scopes as s', 's.id', '=', 'mhr.scope_id')
            ->leftJoin('organizations as o', 'o.id', '=', 's.organization_id')
            ->where('mhr.model_type', $user->getMorphClass())
            ->where('mhr.model_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('mhr.expires_at')->orWhere('mhr.expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('s.organization_id')
                ->orWhere(fn ($q) => $q->where('o.status', 'active')->whereNull('o.deleted_at')))
            ->get(['s.id', 'r.name']);

        $rolesByScope = $rows->groupBy('id')->map(fn ($g) => $g->pluck('name')->values()->all());
        $ids = array_values(array_unique([$globalId, ...$rolesByScope->keys()->map(fn ($id) => (int) $id)->all()]));

        return AccessScope::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn (AccessScope $s) => [
            'id' => $s->getKey(),
            'type' => $s->type,
            'country_id' => $s->country_id,
            'region_id' => $s->region_id,
            'organization' => $s->organization_id ? Organization::query()->whereKey($s->organization_id)
                ->first(['uuid', 'name'])?->only(['uuid', 'name']) : null,
            'roles' => $rolesByScope[$s->getKey()] ?? [],
        ])->all();
    }
}
