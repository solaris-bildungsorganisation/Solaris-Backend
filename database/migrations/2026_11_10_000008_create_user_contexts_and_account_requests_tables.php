<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH — سياقات المستخدم وطلبات نوع الحساب.
 * إسناد الأدوار نفسه في جدول spatie `model_has_roles` (مع scope_id) — لا يوجد جدول إسناد خاص بنا.
 * FR-AUTH-002/004/016/017 · BR-AUTH-003
 */
return new class extends Migration
{
    public function up(): void
    {
        // FR-AUTH-016/017: سياقات المستخدم (قد يملك أكثر من سياق)
        Schema::create('user_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('context_id')->constrained('contexts')->restrictOnDelete();
            $table->string('status', 20)->default('active')->comment('active | pending | suspended | revoked');
            $table->boolean('is_default')->default(false)->comment('السياق الافتراضي عند الدخول');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unsignedBigInteger('default_user_key')->nullable()->virtualAs('IF(is_default, user_id, NULL)')
                ->comment('مولّد — سياق افتراضي واحد لكل مستخدم');
            $table->unique('default_user_key', 'user_contexts_one_default');
            $table->unique(['user_id', 'context_id']);
            $table->comment('FR-AUTH-016/017 — سياقات المستخدم');
        });

        // FR-AUTH-002/004: طلب نوع حساب آخر (معلم، خبير، مؤسسة...) مع الغرض والمراجعة
        Schema::create('account_type_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_role_id')->constrained(config('permission.table_names.roles'))->restrictOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete()
                ->comment('النطاق المطلوب إن وُجد');
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->text('purpose')->comment('الغرض من الطلب — FR-AUTH-004');
            $table->json('attachments')->nullable()->comment('مسارات ملفات داعمة');
            $table->string('status', 20)->default('pending')->index()->comment('pending | under_review | approved | rejected | cancelled');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete()
                ->comment('RESTRICT بسبب CHECK منع المراجعة الذاتية');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('granted_scope_id')->nullable()->constrained('access_scopes')->restrictOnDelete()
                ->comment('النطاق الذي أُسند فيه الدور عند الموافقة');
            $table->timestamps();

            $table->string('open_request_key', 60)->nullable()->virtualAs(
                "IF(status IN ('pending','under_review'), CONCAT(user_id, ':', requested_role_id), NULL)"
            )->comment('مولّد — طلب مفتوح واحد لكل دور');
            $table->unique('open_request_key', 'atr_one_open_per_role');
            $table->index(['user_id', 'status']);
            $table->comment('FR-AUTH-002/004 — طلبات نوع الحساب');
        });

        DB::statement('ALTER TABLE account_type_requests ADD CONSTRAINT atr_no_self_review CHECK (reviewed_by IS NULL OR reviewed_by <> user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('account_type_requests');
        Schema::dropIfExists('user_contexts');
    }
};
