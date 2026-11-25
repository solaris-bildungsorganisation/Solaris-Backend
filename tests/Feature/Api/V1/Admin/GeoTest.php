<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Country;
use App\Models\Language;
use App\Models\Region;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GeoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_meta_countries_returns_active_countries_without_authentication(): void
    {
        Country::query()->create([
            'iso2' => 'SY', 'iso3' => 'SYR',
            'name' => ['ar' => 'سوريا', 'en' => 'Syria', 'de' => 'Syrien'],
            'phone_code' => '+963', 'policy_group' => 'syria', 'is_active' => true,
        ]);
        Country::query()->create([
            'iso2' => 'XX', 'iso3' => 'XXX',
            'name' => ['ar' => 'غير نشط', 'en' => 'Inactive', 'de' => 'Inaktiv'],
            'policy_group' => 'standard', 'is_active' => false,
        ]);

        $this->getJson('/api/v1/meta/countries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.iso2', 'SY');
    }

    public function test_meta_regions_returns_only_active_regions_for_active_country(): void
    {
        $country = Country::query()->create([
            'iso2' => 'SY', 'iso3' => 'SYR',
            'name' => ['ar' => 'سوريا', 'en' => 'Syria', 'de' => 'Syrien'],
            'policy_group' => 'syria', 'is_active' => true,
        ]);
        Region::query()->create([
            'country_id' => $country->id, 'type' => 'governorate', 'code' => 'SY-DI',
            'name' => ['ar' => 'دمشق', 'en' => 'Damascus', 'de' => 'Damaskus'],
            'is_active' => true, 'sort_order' => 1,
        ]);
        Region::query()->create([
            'country_id' => $country->id, 'type' => 'governorate', 'code' => 'SY-XX',
            'name' => ['ar' => 'غير نشط', 'en' => 'Inactive', 'de' => 'Inaktiv'],
            'is_active' => false, 'sort_order' => 2,
        ]);

        $this->getJson("/api/v1/meta/countries/{$country->id}/regions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SY-DI');
    }

    public function test_meta_languages_lists_only_active_languages(): void
    {
        Language::query()->create([
            'code' => 'ar', 'name' => ['ar' => 'العربية', 'en' => 'Arabic', 'de' => 'Arabisch'],
            'native_name' => 'العربية', 'direction' => 'rtl',
            'is_interface' => true, 'is_content' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        Language::query()->create([
            'code' => 'xx', 'name' => ['ar' => 'غير نشطة', 'en' => 'Inactive', 'de' => 'Inaktiv'],
            'native_name' => 'Inactive', 'direction' => 'ltr',
            'is_interface' => false, 'is_content' => false, 'is_active' => false, 'sort_order' => 2,
        ]);

        $this->getJson('/api/v1/meta/languages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ar');
    }

    public function test_admin_geo_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/countries')->assertUnauthorized();
        $this->postJson('/api/v1/admin/countries', [])->assertUnauthorized();
        $this->getJson('/api/v1/admin/regions')->assertUnauthorized();
        $this->getJson('/api/v1/admin/region-policies')->assertUnauthorized();
    }
}
