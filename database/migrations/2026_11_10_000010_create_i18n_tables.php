<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I18N — لغات المستخدم، متطلبات اللغة، ترجمات الواجهة.
 * FR-I18N-002/003/004/006/007/008 · BR-I18N-001/002
 * ملاحظة: لغة المحتوى للدورات تُضاف كـ courses.language_id في الموجة 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FR-I18N-004: لغات المستخدم ومستويات إتقانه — مستقلة عن لغة الواجهة
        Schema::create('user_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $table->string('proficiency', 10)->comment('A1 | A2 | B1 | B2 | C1 | C2 | native');
            $table->boolean('is_native')->default(false);
            $table->string('source', 20)->default('self_declared')->comment('self_declared | assessed | certified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'language_id']);
            $table->comment('FR-I18N-004 — كفاءة المستخدم اللغوية');
        });

        // FR-I18N-006: متطلبات اللغة لبرنامج/دورة/فرصة (polymorphic — الجداول الهدف تأتي في موجات لاحقة)
        Schema::create('language_requirements', function (Blueprint $table) {
            $table->id();
            $table->morphs('requirable');
            $table->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $table->string('min_proficiency', 10)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->string('purpose', 20)->default('instruction')->comment('instruction | prerequisite');
            $table->timestamps();

            $table->unique(['requirable_type', 'requirable_id', 'language_id'], 'lang_req_requirable_lang_unique');
            $table->comment('FR-I18N-006 — متطلبات اللغة للبرنامج/الدورة/الفرصة');
        });

        // FR-I18N-007/008: ترجمات قابلة للإدارة خارج منطق التطبيق
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
        Schema::dropIfExists('user_languages');
    }
};
