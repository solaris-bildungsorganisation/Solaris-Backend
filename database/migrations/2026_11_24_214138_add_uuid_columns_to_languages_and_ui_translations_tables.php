<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->uuid('uuid')->nullable();
        });
    Schema::table('ui_translations', function (Blueprint $table) {
        $table->uuid('uuid')->nullable();
    });

    DB::table('languages')
        ->whereNull('uuid')
        ->orderBy('id')
        ->chunkById(500, function ($languages): void {
            foreach ($languages as $language) {
                DB::table('languages')
                    ->where('id', $language->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        });

    DB::table('ui_translations')
        ->whereNull('uuid')
        ->orderBy('id')
        ->chunkById(500, function ($translations): void {
            foreach ($translations as $translation) {
                DB::table('ui_translations')
                    ->where('id', $translation->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        });

    Schema::table('languages', function (Blueprint $table) {
        $table->uuid('uuid')->nullable(false)->change();
        $table->unique('uuid', 'languages_uuid_unique');
    });

    Schema::table('ui_translations', function (Blueprint $table) {
        $table->uuid('uuid')->nullable(false)->change();
        $table->unique('uuid', 'ui_translations_uuid_unique');
    });
}

public function down(): void
{
    Schema::table('ui_translations', function (Blueprint $table) {
        $table->dropUnique('ui_translations_uuid_unique');
        $table->dropColumn('uuid');
    });

    Schema::table('languages', function (Blueprint $table) {
        $table->dropUnique('languages_uuid_unique');
        $table->dropColumn('uuid');
    });
}
};
