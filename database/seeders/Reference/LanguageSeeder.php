<?php

namespace Database\Seeders\Reference;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** FR-I18N-001/009 — العربية (RTL) والإنجليزية والألمانية من الإصدار الأول. */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $n = fn (string $ar, string $en, string $de) => json_encode(compact('ar', 'en', 'de'), JSON_UNESCAPED_UNICODE);

        $rows = [
            ['code' => 'ar', 'name' => $n('العربية', 'Arabic', 'Arabisch'), 'native_name' => 'العربية', 'direction' => 'rtl', 'sort_order' => 1],
            ['code' => 'en', 'name' => $n('الإنجليزية', 'English', 'Englisch'), 'native_name' => 'English', 'direction' => 'ltr', 'sort_order' => 2],
            ['code' => 'de', 'name' => $n('الألمانية', 'German', 'Deutsch'), 'native_name' => 'Deutsch', 'direction' => 'ltr', 'sort_order' => 3],
        ];

        $rows = array_map(fn ($r) => $r + [
            'is_interface' => true, 'is_content' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $rows);

        DB::table('languages')->upsert($rows, ['code'],
            ['name', 'native_name', 'direction', 'is_interface', 'is_content', 'is_active', 'sort_order', 'updated_at']);

        $this->command?->info('Languages: ' . count($rows));
    }
}
