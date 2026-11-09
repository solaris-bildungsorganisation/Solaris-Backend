<?php

namespace Database\Seeders\Rbac;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContextSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [];
        $order = 1;

        foreach (RbacCatalog::contexts() as $key => [$ar, $en]) {
            $rows[] = [
                'key' => $key,
                'name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => $order++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('contexts')->upsert($rows, ['key'], ['name', 'is_active', 'sort_order', 'updated_at']);

        $this->command?->info('Contexts: ' . count($rows));
    }
}
