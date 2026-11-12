<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH — الدول والمناطق.
 * FR-AUTH-031 (إدارة الدول والمناطق) · FR-AUTH-033 (تمييز سوريا لأغراض السياسة عبر policy_group دون حقل شخصي)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('iso2', 2)->unique();
            $table->char('iso3', 3)->nullable()->unique();
            $table->jsonb('name');
            $table->string('phone_code', 8)->nullable()->comment('+963');
            $table->foreignId('default_language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->string('policy_group', 30)->default('standard')->index()
                ->comment('مجموعة السياسة: syria | stable_market | standard — FR-AUTH-033/034');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->comment('FR-AUTH-031 — الدول');
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('regions')->restrictOnDelete()
                ->comment('هرمية: محافظة ← منطقة ← مدينة');
            $table->string('type', 30)->default('governorate')->comment('governorate | district | city | state');
            $table->string('code', 20)->nullable()->comment('ISO 3166-2 مثل SY-DI');
            $table->jsonb('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index(['country_id', 'parent_id']);
            $table->comment('FR-AUTH-031 — المناطق (هرمية)');
        });

        // ملاحظة MySQL: لا يمكن لـ CHECK أن يشير إلى عمود AUTO_INCREMENT، لذلك منع parent_id = id يتم في الـ Form Request.
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};
