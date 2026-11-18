<?php

namespace App\Services\Rbac;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * رفض إسناد/سحب دور. يُعرض تلقائيًا كاستجابة JSON بالحالة المناسبة (403 غالبًا)
 * مع رمز ثابت (reason) تعتمد عليه الواجهة والاختبارات.
 */
class RoleAssignmentException extends RuntimeException
{
    public const SELF_ASSIGNMENT = 'self_assignment';

    public const SUPER_ADMIN_SEEDER_ONLY = 'super_admin_seeder_only';

    public const ROLE_INACTIVE = 'role_inactive';

    public const SCOPE_TYPE_NOT_ALLOWED = 'scope_type_not_allowed';

    public const MISSING_PERMISSION = 'missing_permission';

    public const SCOPE_OUTSIDE_ASSIGNER = 'scope_outside_assigner';

    public const ROLE_NOT_ASSIGNABLE = 'role_not_assignable';

    public const RANK_TOO_HIGH = 'rank_too_high';

    public const MFA_REQUIRED = 'mfa_required';

    public const TARGET_NOT_MEMBER = 'target_not_member';

    public const REASON_REQUIRED = 'reason_required';

    public const NOT_ASSIGNED = 'not_assigned';

    private const MESSAGES = [
        self::SELF_ASSIGNMENT => 'لا يمكن إسناد دور لنفسك أو سحبه منها.',
        self::SUPER_ADMIN_SEEDER_ONLY => 'دور المدير الأعلى لا يُسنَد عبر الواجهة.',
        self::ROLE_INACTIVE => 'هذا الدور غير مفعّل.',
        self::SCOPE_TYPE_NOT_ALLOWED => 'نوع النطاق غير مسموح لهذا الدور.',
        self::MISSING_PERMISSION => 'لا تملك صلاحية إسناد الأدوار.',
        self::SCOPE_OUTSIDE_ASSIGNER => 'نطاق الإسناد خارج نطاق صلاحياتك.',
        self::ROLE_NOT_ASSIGNABLE => 'لا يحق لك إسناد هذا الدور.',
        self::RANK_TOO_HIGH => 'لا يمكن إسناد دور برتبة مساوية لرتبتك أو أعلى منها.',
        self::MFA_REQUIRED => 'هذه العملية تتطلب التحقق بخطوتين.',
        self::TARGET_NOT_MEMBER => 'المستخدم ليس عضوًا نشطًا في هذه المؤسسة.',
        self::REASON_REQUIRED => 'سبب الإسناد إلزامي للأدوار الإدارية.',
        self::NOT_ASSIGNED => 'الدور غير مُسنَد لهذا المستخدم في هذا النطاق.',
    ];

    public function __construct(public readonly string $reason, public readonly int $status = 403)
    {
        parent::__construct(self::MESSAGES[$reason] ?? $reason);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason], $this->status);
    }
}
