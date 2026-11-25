<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminUserResource extends UserResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */

        public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'status_histories' => $this->whenLoaded('statusHistories'),
        ]);
    }

}
