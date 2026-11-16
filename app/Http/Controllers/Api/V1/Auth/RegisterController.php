<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;


class RegisterController extends Controller
{
#[OA\Post(
    path: '/v1/auth/register',
    summary: 'إنشاء حساب جديد',
    tags: ['Auth'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['first_name', 'last_name', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(
                    property: 'first_name',
                    type: 'string',
                    example: 'أحمد'
                ),
                new OA\Property(
                    property: 'last_name',
                    type: 'string',
                    example: 'محمد'
                ),
                new OA\Property(
                    property: 'display_name',
                    type: 'string',
                    nullable: true,
                    example: 'Ahmed M'
                ),
                new OA\Property(
                    property: 'email',
                    type: 'string',
                    format: 'email',
                    nullable: true,
                    description: 'أرسل البريد أو الهاتف؛ يجب توفير أحدهما',
                    example: 'user@example.com'
                ),
                new OA\Property(
                    property: 'phone',
                    type: 'string',
                    nullable: true,
                    description: 'أرسل الهاتف أو البريد؛ يجب توفير أحدهما',
                    example: '+963912345678'
                ),
                new OA\Property(
                    property: 'account_type',
                    type: 'string',
                    nullable: true,
                    description: 'اختياري؛ إذا لم يُرسل يُستخدم academic_student',
                    example: 'academic_student'
                ),
                new OA\Property(
                    property: 'password',
                    type: 'string',
                    format: 'password',
                    example: 'StrongPassword123!'
                ),
                new OA\Property(
                    property: 'password_confirmation',
                    type: 'string',
                    format: 'password',
                    example: 'StrongPassword123!'
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'تم إنشاء الحساب'
        ),
        new OA\Response(
            response: 422,
            description: 'فشل التحقق من المدخلات أو البريد/الهاتف مستخدم مسبقًا'
        ),
    ]
)]
    /**
     * Handle the incoming request.
     */
    public function __invoke(RegisterRequest $request)
   {
 $data = $request->validated();

    // إذا لم يرسل المستخدم نوع الحساب، يكون طالبًا أكاديميًا.
    $roleKey = $data['account_type'] ?? 'academic_student';

    $role = Role::query()
        ->where('name', $roleKey)
        ->where('is_active', true)
        ->where('is_self_registrable', true)
        ->first();

    if (! $role) {
        return response()->json([
            'message' => 'نوع الحساب غير متاح للتسجيل.',
        ], 422);
    }

    $user = DB::transaction(function () use ($data, $role) {
        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'display_name' => $data['display_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        $typeRequest = $user->accountTypeRequests()->create([
            'requested_role_id' => $role->id,
            'purpose' => 'طلب نوع حساب عند التسجيل',
        ]);

        $typeRequest->status = 'under_review';
        $typeRequest->save();

        return $user;
    });

    return response()->json([
        'message' => 'تم إنشاء الحساب وإرسال نوع الحساب للمراجعة.',
        'data' => [
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'account_status' => $user->status,
            'account_type' => $role->name,
            'account_type_status' => 'under_review',
        ],
    ], 201);
    }
}

