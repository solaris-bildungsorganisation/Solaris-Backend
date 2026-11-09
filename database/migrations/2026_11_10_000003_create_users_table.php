<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH — المستخدمون (الهوية فقط).
 * BR-AUTH-001: المستخدم منفصل عن الدور والسياق — لا يوجد عمود role هنا عن قصد.
 * FR-AUTH-001/007/008/012/018/026/027/030/032 · FR-I18N-003 · NFR-SEC-002/005
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('المعرف العام في الـ API (لا نكشف id)');

            // البيانات الأساسية — FR-AUTH-001/018
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('display_name', 150)->nullable();
            $table->string('email')->nullable()->unique()->comment('فريد — collation utf8mb4_unicode_ci لا يفرّق بين الأحرف — FR-AUTH-008');
            $table->string('phone', 20)->nullable()->unique()->comment('صيغة E.164 — فريد — FR-AUTH-008');
            $table->string('password');
            $table->string('avatar_path')->nullable();
            $table->date('birth_date')->nullable()->comment('بيانات شخصية حساسة — أساس نموذج العمر/القاصرين (FR-CHILD لاحقًا)');

            // حالة الحساب — FR-AUTH-007/012 (السجل في account_status_histories)
            $table->string('status', 30)->default('pending_verification')->index()
                ->comment('pending_verification | active | suspended | blocked | closed');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // اللغة والمنطقة — FR-I18N-003 · FR-AUTH-032
            $table->foreignId('interface_language_id')->nullable()->constrained('languages')->nullOnDelete()
                ->comment('لغة الواجهة فقط — مستقلة عن كفاءة المستخدم (user_languages)');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('timezone', 64)->default('UTC');

            // الأمان — FR-AUTH-026/027 · NFR-SEC-002
            $table->text('two_factor_secret')->nullable()->comment('مشفّر (encrypted cast)');
            $table->text('two_factor_recovery_codes')->nullable()->comment('مشفّر');
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('null = تسجيل ذاتي');
            $table->timestamps();
            $table->softDeletes()->comment('لا حذف فعلي — FR-AUTH-007/030');

            $table->index(['country_id', 'region_id']);
            $table->comment('FR-AUTH-001.. — هوية المستخدم (بدون أدوار)');
        });

        // FR-AUTH-008: unique عادي يكفي في MySQL — يسمح بعدة NULL، والـ collation لا يفرّق بين حالة الأحرف.
        // يشمل الحسابات المغلقة/المحذوفة ناعمًا (لا إعادة استخدام للبريد).
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_contact_required CHECK (email IS NOT NULL OR phone IS NOT NULL)");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending_verification','active','suspended','blocked','closed'))");

        // FR-AUTH-027: منع إعادة استخدام كلمات المرور السابقة
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->comment('FR-AUTH-027 — سجل كلمات المرور السابقة (hash فقط)');
        });

        // FR-AUTH-007/012/013/023/029: كل انتقال حالة قابل للتتبع
        Schema::create('account_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete()->comment('null = النظام');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->comment('FR-AUTH-007/012/013/023 — سجل حالات الحساب');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_status_histories');
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('users');
    }
};
