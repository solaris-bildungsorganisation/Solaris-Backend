<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Organization;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GeoAdministrationService
{
    private const COUNTRY_FIELDS = ['iso2', 'iso3', 'name', 'phone_code', 'default_language_id', 'policy_group', 'is_active'];
    private const REGION_FIELDS = ['country_id', 'parent_id', 'type', 'code', 'name', 'is_active', 'sort_order'];

    public function __construct(private readonly GeoReferenceCache $cache)
    {
    }

    public function createCountry(User $actor, array $data): Country
    {
        $country = DB::transaction(function () use ($actor, $data) {
            $country = Country::query()->create(Arr::only($data, self::COUNTRY_FIELDS));
            $this->audit($actor, $country, 'country.created', [], $country->only(self::COUNTRY_FIELDS));
            return $country;
        });
        $this->cache->forgetCountries();
        return $country->load('defaultLanguage');
    }

    public function updateCountry(User $actor, Country $country, array $data): Country
    {
        $country = DB::transaction(function () use ($actor, $country, $data) {
            $before = $country->only(self::COUNTRY_FIELDS);
            $country->fill(Arr::only($data, self::COUNTRY_FIELDS))->save();
            $this->audit($actor, $country, 'country.updated', $before, $country->only(self::COUNTRY_FIELDS));
            return $country->refresh();
        });
        $this->cache->forgetCountries();
        $this->cache->forgetRegionsForCountry((int) $country->id);
        return $country->load('defaultLanguage');
    }

    /** @return array{deleted: bool, country: Country} */
    public function deleteCountry(User $actor, Country $country): array
    {
        $countryId = (int) $country->id;
        $result = DB::transaction(function () use ($actor, $country) {
            $used = $country->regions()->exists()
                || $country->regionPolicies()->exists()
                || User::withTrashed()->where('country_id', $country->id)->exists()
                || Organization::withTrashed()->where('country_id', $country->id)->exists();
            $before = $country->only(self::COUNTRY_FIELDS);
            if ($used) {
                $country->forceFill(['is_active' => false])->save();
                $this->audit($actor, $country, 'country.deactivated', $before, $country->only(self::COUNTRY_FIELDS));
                return ['deleted' => false, 'country' => $country->refresh()];
            }
            $this->audit($actor, $country, 'country.deleted', $before, []);
            $country->delete();
            return ['deleted' => true, 'country' => $country];
        });
        $this->cache->forgetCountries();
        $this->cache->forgetRegionsForCountry($countryId);
        return $result;
    }

    public function createRegion(User $actor, array $data): Region
    {
        $region = DB::transaction(function () use ($actor, $data) {
            $region = Region::query()->create(Arr::only($data, self::REGION_FIELDS));
            $this->audit($actor, $region, 'region.created', [], $region->only(self::REGION_FIELDS));
            return $region;
        });
        $this->cache->forgetRegionsForCountry((int) $region->country_id);
        return $region->load(['country', 'parent']);
    }

    public function updateRegion(User $actor, Region $region, array $data): Region
    {
        $oldCountryId = (int) $region->country_id;
        $region = DB::transaction(function () use ($actor, $region, $data) {
            $before = $region->only(self::REGION_FIELDS);
            $region->fill(Arr::only($data, self::REGION_FIELDS))->save();
            $this->audit($actor, $region, 'region.updated', $before, $region->only(self::REGION_FIELDS));
            return $region->refresh();
        });
        $this->cache->forgetRegionsForCountry($oldCountryId);
        $this->cache->forgetRegionsForCountry((int) $region->country_id);
        return $region->load(['country', 'parent']);
    }

    /** @return array{deleted: bool, region: Region} */
    public function deleteRegion(User $actor, Region $region): array
    {
        $countryId = (int) $region->country_id;
        $result = DB::transaction(function () use ($actor, $region) {
            $used = $region->children()->exists()
                || $region->regionPolicies()->exists()
                || User::withTrashed()->where('region_id', $region->id)->exists()
                || Organization::withTrashed()->where('region_id', $region->id)->exists();
            $before = $region->only(self::REGION_FIELDS);
            if ($used) {
                $region->forceFill(['is_active' => false])->save();
                $this->audit($actor, $region, 'region.deactivated', $before, $region->only(self::REGION_FIELDS));
                return ['deleted' => false, 'region' => $region->refresh()];
            }
            $this->audit($actor, $region, 'region.deleted', $before, []);
            $region->delete();
            return ['deleted' => true, 'region' => $region];
        });
        $this->cache->forgetRegionsForCountry($countryId);
        return $result;
    }

    private function audit(User $actor, Model $subject, string $event, array $before, array $after): void
    {
        activity('admin')->causedBy($actor)->performedOn($subject)
            ->tap(function ($activity) use ($actor): void {
                $activity->sensitivity = 'sensitive';
                $activity->ip_address = request()?->ip();
                $activity->user_agent = request()?->userAgent();
                $activity->context_id = $actor->effectiveAccess()->activeContextId;
            })
            ->withProperties(['before' => $before, 'after' => $after])
            ->event($event)->log($event);
    }
}
