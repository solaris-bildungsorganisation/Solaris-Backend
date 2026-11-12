<?php

namespace Database\Seeders\Reference;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FR-AUTH-031/033 — سوريا (مجموعة سياسة syria) وألمانيا (stable_market) + محافظات سوريا الـ 14 (ISO 3166-2:SY).
 * يعتمد على LanguageSeeder.
 */
class CountryRegionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $lang = DB::table('languages')->pluck('id', 'code');
        $n = fn (string $ar, string $en, ?string $de = null) => json_encode(array_filter(compact('ar', 'en', 'de')), JSON_UNESCAPED_UNICODE);

        DB::table('countries')->upsert([
            ['iso2' => 'SY', 'iso3' => 'SYR', 'name' => $n('سوريا', 'Syria', 'Syrien'), 'phone_code' => '+963',
                'default_language_id' => $lang['ar'], 'policy_group' => 'syria', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'name' => $n('ألمانيا', 'Germany', 'Deutschland'), 'phone_code' => '+49',
                'default_language_id' => $lang['de'], 'policy_group' => 'stable_market', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['iso2'], ['iso3', 'name', 'phone_code', 'default_language_id', 'policy_group', 'is_active', 'updated_at']);

        $syria = DB::table('countries')->where('iso2', 'SY')->value('id');

        $governorates = [
            ['SY-DI', 'دمشق', 'Damascus'], ['SY-RD', 'ريف دمشق', 'Rif Dimashq'], ['SY-HL', 'حلب', 'Aleppo'],
            ['SY-HI', 'حمص', 'Homs'], ['SY-HM', 'حماة', 'Hama'], ['SY-LA', 'اللاذقية', 'Latakia'],
            ['SY-TA', 'طرطوس', 'Tartus'], ['SY-ID', 'إدلب', 'Idlib'], ['SY-RA', 'الرقة', 'Raqqa'],
            ['SY-DY', 'دير الزور', 'Deir ez-Zor'], ['SY-HA', 'الحسكة', 'Al-Hasakah'], ['SY-DR', 'درعا', 'Daraa'],
            ['SY-SU', 'السويداء', 'As-Suwayda'], ['SY-QU', 'القنيطرة', 'Quneitra'],
        ];

        $rows = [];
        foreach ($governorates as $i => [$code, $ar, $en]) {
            $rows[] = ['country_id' => $syria, 'parent_id' => null, 'type' => 'governorate', 'code' => $code,
                'name' => $n($ar, $en), 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('regions')->upsert($rows, ['country_id', 'code'], ['name', 'type', 'is_active', 'sort_order', 'updated_at']);

        $this->command?->info('Countries: 2, Regions: ' . count($rows));
    }
}
