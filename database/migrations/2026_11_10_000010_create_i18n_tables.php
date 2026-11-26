<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('language_requirements', function (Blueprint $table) {
            $table->id();
            $table->morphs('requirable');
            $table->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $table->string('min_proficiency', 10)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->string('purpose', 20)->default('instruction')->comment('instruction | prerequisite');
            $table->timestamps();
        $table->unique(
            ['requirable_type', 'requirable_id', 'language_id'],
            'lang_req_requirable_lang_unique'
        );

        $table->comment('FR-I18N-006 — متطلبات اللغة للبرنامج/الدورة/الفرصة');
    });

    Schema::create('ui_translations', function (Blueprint $table) {
        $table->id();
        $table->string('group', 50)->comment('validation | errors | notifications | emails | frontend');
        $table->string('key', 150);
        $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
        $table->text('value');
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();

        $table->unique(['group', 'key', 'language_id']);
        $table->comment('FR-I18N-007/008 — ترجمات الواجهة والرسائل');
    });
}

public function down(): void
{
    Schema::dropIfExists('ui_translations');
    Schema::dropIfExists('language_requirements');
}
};
