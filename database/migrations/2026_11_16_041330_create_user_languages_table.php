<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_languages', function (Blueprint $table) {
            $table->id();

            // ربط المستخدم
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // ربط اللغة
            $table->foreignId('language_id')
                ->constrained('languages')
                ->restrictOnDelete();

            // مستوى إتقان اللغة
            $table->string('proficiency', 10);

            // هل اللغة هي اللغة الأم؟
            $table->boolean('is_native')->default(false);

            // معلومات التحقق - يتحكم بها النظام
            $table->timestamp('verified_at')->nullable();
            $table->string('source')->nullable();

            $table->timestamps();

            // منع تكرار اللغة للمستخدم نفسه
            $table->unique(
                ['user_id', 'language_id'],
                'user_language_unique'
            );

            $table->index(['user_id', 'proficiency']);

            $table->comment('B3 - User language proficiency');
        });
        DB::statement("
    ALTER TABLE user_languages
    ADD CONSTRAINT user_languages_proficiency_check
    CHECK (proficiency IN ('A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'native'))
");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_languages');
    }
};
