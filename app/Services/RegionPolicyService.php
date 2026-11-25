<?php

namespace App\Services;

use App\Models\Region;
use App\Models\RegionAccessPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RegionPolicyService
{
    private const EDITABLE = ['policy_key', 'country_id', 'region_id', 'value', 'is_active', 'effective_from', 'effective_to'];

    public function create(User $actor, array $data): RegionAccessPolicy
    {
        return DB::transaction(function () use ($actor, $data) {
            $policy = RegionAccessPolicy::query()->create([
                ...Arr::only($data, self::EDITABLE),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit($actor, $policy, 'region_policy.created', [], $this->snapshot($policy));
            return $policy->refresh()->load(['country', 'region']);
        });
    }

    public function update(User $actor, RegionAccessPolicy $policy, array $data): RegionAccessPolicy
    {
        return DB::transaction(function () use ($actor, $policy, $data) {
            $before = $this->snapshot($policy);
            $policy->fill(Arr::only($data, self::EDITABLE));
            $policy->forceFill(['updated_by' => $actor->id])->save();
            $this->audit($actor, $policy, 'region_policy.updated', $before, $this->snapshot($policy));
            return $policy->refresh()->load(['country', 'region']);
        });
    }

    public function delete(User $actor, RegionAccessPolicy $policy): void
    {
        DB::transaction(function () use ($actor, $policy): void {
            $this->audit($actor, $policy, 'region_policy.deleted', $this->snapshot($policy), []);
            $policy->delete();
        });
    }

    /** المنطقة الحالية ← الآباء من الأقرب للأبعد ← الدولة ← القيمة العامة. */
    public function get(string $key, User $user): mixed
    {
        $definitions = config('solaris.region_policy_keys', []);
        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Unknown region policy key [{$key}].");
        }

        $countryId = $user->country_id !== null ? (int) $user->country_id : null;
        $regionId = $user->region_id !== null ? (int) $user->region_id : null;
        $regionIds = [];
        $visited = [];

        while ($regionId !== null && ! in_array($regionId, $visited, true)) {
            $region = Region::query()->find($regionId);
            if (! $region || ($countryId !== null && $countryId !== (int) $region->country_id)) {
                break;
            }
            $countryId ??= (int) $region->country_id;
            $regionIds[] = (int) $region->id;
            $visited[] = (int) $region->id;
            $regionId = $region->parent_id === null ? null : (int) $region->parent_id;
        }

        foreach ($regionIds as $id) {
            $policy = $this->effective($key)->where('region_id', $id)->where('country_id', $countryId)->first();
            if ($policy) {
                return $policy->value;
            }
        }
        if ($countryId !== null) {
            $policy = $this->effective($key)->where('country_id', $countryId)->whereNull('region_id')->first();
            if ($policy) {
                return $policy->value;
            }
        }
        return $this->effective($key)->whereNull('country_id')->whereNull('region_id')->first()?->value;
    }

    private function effective(string $key)
    {
        return RegionAccessPolicy::query()->effective()->where('policy_key', $key);
    }

    private function snapshot(RegionAccessPolicy $policy): array
    {
        return Arr::only($policy->getAttributes(), ['policy_key', 'country_id', 'region_id', 'value', 'is_active', 'effective_from', 'effective_to']);
    }

    private function audit(User $actor, Model $subject, string $event, array $before, array $after): void
    {
        activity('security')->causedBy($actor)->performedOn($subject)
            ->tap(function ($activity) use ($actor): void {
                $activity->sensitivity = 'restricted';
                $activity->ip_address = request()?->ip();
                $activity->user_agent = request()?->userAgent();
                $activity->context_id = $actor->effectiveAccess()->activeContextId;
            })
            ->withProperties(['before' => $before, 'after' => $after])
            ->event($event)->log($event);
    }
}
