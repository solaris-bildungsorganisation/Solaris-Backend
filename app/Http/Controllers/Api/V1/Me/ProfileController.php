<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\Language;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class ProfileController extends Controller
{
    /**
     * GET /me
     * عرض الملف الشخصي للمستخدم الحالي.
     */
    #[OA\Get(
        path: '/v1/me',
        tags: ['Profile'],
        summary: 'عرض الملف الشخصي للمستخدم الحالي',
        security: [['bearerAuth' => []]], // <--- أضفنا الحماية هنا لإظهار القفل
        responses: [
            new OA\Response(response: 200, description: 'تم جلب الملف الشخصي بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
        ]
    )]
    public function show(Request $request)
    {
        $user = $request->user()->load([
            'interfaceLanguage',
            'userLanguages.language',
            'roles.context',
            'userContexts.context',
            'memberships.organization',
        ]);

        return new UserResource($user);
    }

    /**
     * PATCH /me
     * تعديل بيانات الملف الشخصي.
     */
    #[OA\Patch(
        path: '/v1/me',
        tags: ['Profile'],
        summary: 'تعديل بيانات الملف الشخصي',
        security: [['bearerAuth' => []]], // <--- أضفنا الحماية هنا
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Ahmad'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Ali'),
                    new OA\Property(property: 'display_name', type: 'string', nullable: true, example: 'Ahmad'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'Asia/Damascus'),
                    new OA\Property(property: 'interface_language_id', type: 'integer', nullable: true, example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم تعديل الملف الشخصي بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 422, description: 'فشل التحقق من المدخلات'),
        ]
    )]
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'timezone' => ['sometimes', 'timezone'],
            'interface_language_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('languages', 'id')->where('is_active', true)->where('is_interface', true),
            ],
        ]);

        $oldValues = $user->only(array_keys($validated));

        $user->fill($validated);
        $user->save();

        $newValues = $user->only(array_keys($validated));

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'old' => $oldValues,
                'attributes' => $newValues,
            ])
            ->log('تم تعديل الملف الشخصي');

        return new UserResource(
            $user->load([
                'interfaceLanguage',
                'userLanguages.language',
                'roles.context',
                'userContexts.context',
                'memberships.organization',
            ])
        );
    }

    /**
     * POST /me/avatar
     * رفع الصورة الشخصية.
     */
    #[OA\Post(
        path: '/v1/me/avatar',
        tags: ['Profile'],
        summary: 'رفع الصورة الشخصية',
        security: [['bearerAuth' => []]], // <--- أضفنا الحماية هنا
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['avatar'],
                    properties: [
                        new OA\Property(
                            property: 'avatar',
                            type: 'string',
                            format: 'binary',
                            description: 'صورة JPG أو PNG أو WEBP، بحد أقصى 2MB'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'تم رفع الصورة بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
            new OA\Response(response: 422, description: 'الصورة غير صالحة أو تجاوزت الحجم المسموح'),
        ]
    )]
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        $user = $request->user();
        $file = $request->file('avatar');

        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => abort(422, 'نوع الصورة غير مدعوم.'),
        };

        $filename = (string) \Illuminate\Support\Str::uuid() . '.' . $extension;

        $path = $file->storeAs('avatars', $filename, 'public');

        $oldAvatar = $user->avatar_path;

        $user->avatar_path = $path;
        $user->save();

        if ($oldAvatar) {
            Storage::disk('public')->delete($oldAvatar);
        }

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'old' => ['avatar_path' => $oldAvatar],
                'attributes' => ['avatar_path' => $path],
            ])
            ->log('تم تغيير الصورة الشخصية');

        return new UserResource($user->load('interfaceLanguage'));
    }

    /**
     * DELETE /me/avatar
     * حذف الصورة الشخصية.
     */
    #[OA\Delete(
        path: '/v1/me/avatar',
        tags: ['Profile'],
        summary: 'حذف الصورة الشخصية',
        security: [['bearerAuth' => []]], // <--- أضفنا الحماية هنا أيضاً
        responses: [
            new OA\Response(response: 200, description: 'تم حذف الصورة الشخصية بنجاح'),
            new OA\Response(response: 401, description: 'غير مصرح'),
        ]
    )]
    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $oldAvatar = $user->avatar_path;

        $user->avatar_path = null;
        $user->save();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'old' => ['avatar_path' => $oldAvatar],
                'attributes' => ['avatar_path' => null],
            ])
            ->log('تم حذف الصورة الشخصية');

        return response()->json([
            'message' => 'تم حذف الصورة الشخصية بنجاح.',
        ]);
    }
}
