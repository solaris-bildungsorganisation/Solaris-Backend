<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'uuid' => $user->uuid,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,

            'email' => $user->email,
            'phone' => $user->phone,

            'avatar_url' => $user->avatar_path
                ? asset('storage/' . $user->avatar_path)
                : null,

            'birth_date' => $user->birth_date,
            'timezone' => $user->timezone,

            'interface_language' => $user->interfaceLanguage
                ? [
                    'id' => $user->interfaceLanguage->id,
                    'code' => $user->interfaceLanguage->code,
                    'name' => $user->interfaceLanguage->name,
                    'native_name' => $user->interfaceLanguage->native_name,
                    'direction' => $user->interfaceLanguage->direction,
                ]
                : null,

            'languages' => $user->userLanguages->map(fn ($userLanguage) => [
                'id' => $userLanguage->id,
                'language' => [
                    'id' => $userLanguage->language->id,
                    'code' => $userLanguage->language->code,
                    'name' => $userLanguage->language->name,
                    'native_name' => $userLanguage->language->native_name,
                ],
                'proficiency' => $userLanguage->proficiency,
                'is_native' => $userLanguage->is_native,
                'verified_at' => $userLanguage->verified_at,
            ]),

            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'context' => $role->context
                    ? [
                        'key' => $role->context->key,
                        'name' => $role->context->name,
                    ]
                    : null,
                'scope_id' => $role->pivot->scope_id ?? null,
            ]),

            'contexts' => $user->userContexts->map(fn ($userContext) => [
                'id' => $userContext->id,
                'status' => $userContext->status,
                'is_default' => $userContext->is_default,
                'activated_at' => $userContext->activated_at,
                'deactivated_at' => $userContext->deactivated_at,
                'context' => $userContext->context
                    ? [
                        'key' => $userContext->context->key,
                        'name' => $userContext->context->name,
                        'description' => $userContext->context->description,
                    ]
                    : null,
            ]),

            'active_context' => $user->userContexts
                ->first(fn ($item) => $item->status === 'active' && $item->is_default)
                ?->context
                ?->only(['key', 'name', 'description']),

            'organizations' => $user->memberships->map(fn ($membership) => [
                'membership_type' => $membership->membership_type,
                'status' => $membership->status,
                'title' => $membership->title,
                'joined_at' => $membership->joined_at,
                'left_at' => $membership->left_at,
                'organization' => $membership->organization
                    ? [
                        'uuid' => $membership->organization->uuid,
                        'name' => $membership->organization->name,
                        'slug' => $membership->organization->slug,
                        'type' => $membership->organization->type,
                    ]
                    : null,
            ]),
        ];
    }
}
