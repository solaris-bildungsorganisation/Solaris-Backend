<?php

namespace App\Services;

use App\Models\AccessScope;
use App\Models\Organization;
use App\Models\Region;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrganizationService
{
    private const EDITABLE = [
        'name', 'type', 'country_id', 'region_id', 'registration_number',
        'email', 'phone', 'website', 'address', 'logo_path', 'description', 'settings',
    ];

    public function create(User $actor, array $data): Organization
    {
        return DB::transaction(function () use ($actor, $data) {
            $this->assertRegionMatchesCountry($data['country_id'] ?? null, $data['region_id'] ?? null);

            $organization = new Organization();
            $organization->fill(Arr::only($data, self::EDITABLE));
            $organization->slug = $this->uniqueSlug($data['name']);
            $organization->forceFill([
                'status' => 'pending',
                'created_by' => $actor->getKey(),
            ]);
            $organization->save();

            // إنشاء سجل نطاق بيانات المؤسسة. لا يبدل نطاق صلاحيات المستخدم الحالي.
            AccessScope::forOrganization($organization);

            $this->log($actor, $organization, 'created', 'organization.created', [
                'type' => $organization->type,
                'country_id' => $organization->country_id,
                'region_id' => $organization->region_id,
            ]);

            return $organization->loadCount('members');
        });
    }

    public function update(User $actor, Organization $organization, array $data): Organization
    {
        return DB::transaction(function () use ($actor, $organization, $data) {
            $attributes = Arr::only($data, self::EDITABLE);
            $countryId = array_key_exists('country_id', $attributes)
                ? $attributes['country_id']
                : $organization->country_id;
            $regionId = array_key_exists('region_id', $attributes)
                ? $attributes['region_id']
                : $organization->region_id;

            $this->assertRegionMatchesCountry($countryId, $regionId);
            $organization->fill($attributes);
            $organization->save();

            $this->log($actor, $organization, 'updated', 'organization.updated', [
                'changed_fields' => array_keys($attributes),
            ]);

            return $organization->refresh()->loadCount('members');
        });
    }

    public function changeStatus(User $actor, Organization $organization, string $newStatus, string $reason): Organization
    {
        return DB::transaction(function () use ($actor, $organization, $newStatus, $reason) {
            $allowedTransitions = match ($organization->status) {
                'pending' => ['active', 'archived'],
                'active' => ['suspended', 'archived'],
                'suspended' => ['active', 'archived'],
                'archived' => [],
                default => [],
            };

            if (! in_array($newStatus, $allowedTransitions, true)) {
                throw ValidationException::withMessages([
                    'status' => ["الانتقال من {$organization->status} إلى {$newStatus} غير مسموح."],
                ]);
            }

            $oldStatus = $organization->status;
            $organization->forceFill(['status' => $newStatus])->save();

            $this->log($actor, $organization, 'status_changed', 'organization.status_changed', [
                'from' => $oldStatus,
                'to' => $newStatus,
                'reason' => $reason,
            ]);

            return $organization->refresh()->loadCount('members');
        });
    }

    public function verify(User $actor, Organization $organization): Organization
    {
        return DB::transaction(function () use ($actor, $organization) {
            $organization->forceFill([
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ])->save();

            $this->log($actor, $organization, 'verified', 'organization.verified', []);

            return $organization->refresh()->loadCount('members');
        });
    }

    private function assertRegionMatchesCountry(?int $countryId, ?int $regionId): void
    {
        if ($regionId === null) {
            return;
        }

        $valid = Region::query()
            ->whereKey($regionId)
            ->where('country_id', $countryId)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'region_id' => ['المنطقة المحددة لا تتبع الدولة المحددة.'],
            ]);
        }
    }

    private function uniqueSlug(array|string $name): string
    {
        $source = is_array($name) ? ($name['en'] ?? $name['ar'] ?? 'organization') : $name;
        $base = Str::slug((string) $source) ?: 'organization';
        $slug = $base;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    private function log(User $actor, Organization $organization, string $event, string $description, array $properties): void
    {
        activity('org')
            ->causedBy($actor)
            ->performedOn($organization)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
