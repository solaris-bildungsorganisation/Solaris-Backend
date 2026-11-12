<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH/SEC — التوكنات (Sanctum)، الجلسات، رموز التحقق، أحداث المصادقة.
 * FR-AUTH-009/010/011/024/025/026 · NFR-SEC-001/006
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel Sanctum (البنية القياسية — لا تنشر migration الحزمة مرة أخرى)
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->comment('Laravel Sanctum tokens');
        });

        // FR-AUTH-024/025: جلسات قابلة للعرض والإنهاء + السياق الفعّال لكل جلسة
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->unique()
                ->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('active_context_id')->nullable()->constrained('contexts')->nullOnDelete()
                ->comment('تبديل السياق يغيّر مجموعة الصلاحيات — FR-AUTH-016');
            $table->foreignId('active_scope_id')->nullable()->constrained('access_scopes')->nullOnDelete()
                ->comment('النطاق الفعّال للجلسة → setPermissionsTeamId() في middleware');
            $table->string('device_name', 150)->nullable();
            $table->string('platform', 20)->nullable()->comment('web | pwa | android | ios');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 30)->nullable()->comment('logout | admin_terminated | password_changed | expired | security');
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->comment('FR-AUTH-024/025 — جلسات المستخدم');
        });

        // FR-AUTH-010/011: OTP / روابط تحقق (hash فقط)
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('channel', 10)->comment('email | sms | whatsapp');
            $table->string('destination')->comment('البريد/الهاتف وقت الإرسال');
            $table->string('purpose', 30)->comment('email_verification | phone_verification | password_reset | mfa_login | contact_change');
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'purpose', 'consumed_at']);
            $table->index(['destination', 'purpose']);
            $table->comment('FR-AUTH-010/011 — رموز التحقق واستعادة كلمة المرور');
        });

        // FR-AUTH-024/026: أحداث المصادقة (حجم عالٍ — منفصلة عن activity_log وسياسة احتفاظ مختلفة)
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('session_id')->nullable();
            $table->string('event', 40)->comment('login_succeeded | login_failed | logout | lockout | password_reset_requested | password_reset_completed | password_changed | mfa_enabled | mfa_failed | session_revoked | context_switched');
            $table->string('identifier_hash', 64)->nullable()->comment('sha256 للمعرّف المُدخل عند الفشل — لا نخزن القيمة الخام');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->comment('FR-AUTH-024/026 — سجل أحداث الدخول والأمان');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('verification_codes');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('personal_access_tokens');
    }
};
