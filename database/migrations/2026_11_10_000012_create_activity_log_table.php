<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT — سجل التدقيق (spatie/laravel-activitylog) موسّع بحقول الأمن.
 * FR-AUTH-023/029 · FR-ADMIN-011 · NFR-SEC-007 · BR-SEC-003 (لا تعديل دون أثر)
 * لا تنشر migrations الحزمة — هذا الملف يجمعها (create + event + batch_uuid) مع الإضافات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index()->comment('auth | rbac | org | admin | security ...');
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->jsonb('properties')->nullable()->comment('old/new — بدون كلمات مرور أو أسرار');
            $table->uuid('batch_uuid')->nullable();

            // إضافات زولاريس
            $table->uuid('session_id')->nullable();
            $table->foreignId('context_id')->nullable()->constrained('contexts')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('sensitivity', 20)->default('internal')->comment('internal | personal | sensitive | restricted');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['causer_type', 'causer_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->comment('FR-ADMIN-011 · NFR-SEC-007 — سجل تدقيق غير قابل للتعديل');
        });

        // BR-SEC-003: السجل append-only. الحذف مسموح فقط لمهمة الاحتفاظ عبر متغير الجلسة:
        //   SET @solaris_allow_audit_purge = 1;  ... DELETE ...;  SET @solaris_allow_audit_purge = NULL;
        // ملاحظة MySQL: مع تفعيل binary log يحتاج إنشاء Trigger صلاحية SUPER أو log_bin_trust_function_creators=1 على السيرفر.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_log_block_update BEFORE UPDATE ON activity_log
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only (update blocked)'
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER activity_log_block_delete BEFORE DELETE ON activity_log
            FOR EACH ROW BEGIN
                IF COALESCE(@solaris_allow_audit_purge, 0) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only (delete blocked)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_block_delete');
        Schema::dropIfExists('activity_log');
    }
};
