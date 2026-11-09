<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH — السياقات الوظيفية.
 * السياق ليس دورًا إداريًا (FR-AUTH-016): هو "الوضع" الذي يعمل فيه المستخدم (تعلم، تدريس، إدارة برنامج...).
 * كل دور في جدول spatie `roles` ينتمي لسياق واحد عبر roles.context_id (migration 000007).
 * FR-AUTH-016/017
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contexts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique()
                ->comment('learning | teaching | review | content | mentoring | pss | program_management | recruitment | staff | organization | administration');
            $table->json('name')->comment('{"ar":"…","en":"…","de":"…"}');
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->comment('FR-AUTH-016/017 — السياق الوظيفي');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contexts');
    }
};
