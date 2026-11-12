<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I18N — اللغات المدعومة في المنصة.
 * FR-I18N-001 (ar/en/de من الإصدار الأول) · FR-I18N-007 (إضافة لغات دون إعادة بناء) · FR-I18N-009 (RTL)
 * نفس الجدول يخدم: لغة الواجهة، لغة المحتوى، ولغات المستخدم (كيانات مستقلة — BR-I18N-001/002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('BCP-47: ar, en, de');
            $table->jsonb('name')->comment('ترجمات الاسم {"ar":"العربية","en":"Arabic"}');
            $table->string('native_name', 100);
            $table->string('direction', 3)->default('ltr')->comment('rtl | ltr');
            $table->boolean('is_interface')->default(false)->comment('متاحة كلغة واجهة');
            $table->boolean('is_content')->default(true)->comment('متاحة كلغة محتوى');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->comment('FR-I18N-001/007/009 — اللغات المدعومة');
        });

        DB::statement("ALTER TABLE languages ADD CONSTRAINT languages_direction_check CHECK (direction IN ('rtl','ltr'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
