<?php

namespace App\Services;

use App\Models\Country;
use App\Models\Language;
use App\Models\Region;
use Illuminate\Support\Facades\Cache;

class GeoReferenceCache
{
 public function countries(string $locale): array
{
    return Cache::remember(
        $this->countriesKey($locale),
        $this->ttl(),
        function () use ($locale): array {
            return Country::query()
                ->active()
                ->with('defaultLanguage:id,code,name,native_name')
                ->orderBy('iso2')
                ->get()
                ->map(function ($country) use ($locale): array {
                    /** @var Country $country */
                    return [
                        'id' => $country->id,
                        'iso2' => $country->iso2,
                        'iso3' => $country->iso3,
                        'name' => $country->getTranslation('name', $locale),
                        'translations' => $country->getTranslations('name'),
                        'phone_code' => $country->phone_code,
                        'default_language' => $country->defaultLanguage
                            ? [
                                'id' => $country->defaultLanguage->id,
                                'code' => $country->defaultLanguage->code,
                                'name' => $country->defaultLanguage->getTranslation('name', $locale),
                                'native_name' => $country->defaultLanguage->native_name,
                            ]
                            : null,
                    ];
                })
                ->all();
        }
    );
}

   public function regions(Country $country, string $locale): array
{
    return Cache::remember(
        $this->regionsKey((int) $country->id, $locale),
        $this->ttl(),
        function () use ($country, $locale): array {
            return Region::query()
                ->where('country_id', $country->id)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function ($region) use ($locale): array {
                    /** @var Region $region */
                    return [
                        'id' => $region->id,
                        'country_id' => $region->country_id,
                        'parent_id' => $region->parent_id,
                        'type' => $region->type,
                        'code' => $region->code,
                        'name' => $region->getTranslation('name', $locale),
                        'translations' => $region->getTranslations('name'),
                        'sort_order' => $region->sort_order,
                    ];
                })
                ->all();
        }
    );
}

 public function languages(string $locale): array
{
    return Cache::remember(
        $this->languagesKey($locale),
        $this->ttl(),
        function () use ($locale): array {
            return Language::query()
                ->active()
                ->get()
                ->map(function ($language) use ($locale): array {
                    /** @var Language $language */
                    return [
                        'id' => $language->id,
                        'code' => $language->code,
                        'name' => $language->getTranslation('name', $locale),
                        'native_name' => $language->native_name,
                        'direction' => $language->direction,
                    ];
                })
                ->all();
        }
    );
}

    public function forgetCountries(): void
    {
        foreach ($this->locales() as $locale) {
            Cache::forget($this->countriesKey($locale));
        }
    }

    public function forgetRegionsForCountry(int $countryId): void
    {
        foreach ($this->locales() as $locale) {
            Cache::forget($this->regionsKey($countryId, $locale));
        }
    }

    private function locales(): array
    {
        return array_values(array_unique(array_filter([
            'ar',
            'en',
            'de',
            app()->getLocale(),
            config('app.fallback_locale'),
            ...Language::query()->pluck('code')->all(),
        ])));
    }

    private function ttl(): int
    {
        return max(1, (int) config('solaris.geo_reference_cache_seconds', 3600));
    }

    private function countriesKey(string $locale): string
    {
        return 'meta:geo:countries:' . $locale;
    }

    private function regionsKey(int $countryId, string $locale): string
    {
        return 'meta:geo:country:' . $countryId . ':regions:' . $locale;
    }

    private function languagesKey(string $locale): string
    {
        return 'meta:geo:languages:' . $locale;
    }
}
