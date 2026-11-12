<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COM — الإشعارات: Event → Policy → Template → Channel → Delivery Attempt → Status
 * FR-COM-001..009 · وثيقة التحليل §18
 */
return new class extends Migration
{
    public function up(): void
    {
        // FR-COM-009: قنوات متعددة دون ربط معماري بقناة واحدة
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique()->comment('in_app | email | sms | push | whatsapp');
            $table->jsonb('name');
            $table->string('driver', 50)->comment('اسم الـ adapter في الكود');
            $table->boolean('supports_sensitive')->default(false)->comment('هل القناة مناسبة لمحتوى حساس — FR-COM-008');
            $table->boolean('is_active')->default(true);
            $table->jsonb('config')->nullable()->comment('إعدادات غير سرية فقط (الأسرار في .env)');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->comment('FR-COM-009 — قنوات الإرسال');
        });

        // FR-COM-001..006: كتالوج أنواع الإشعارات (الأحداث)
        Schema::create('notification_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique()->comment('مثل enrollment.accepted | session.reminder | security.new_login');
            $table->string('category', 30)->index()->comment('account | enrollment | session | result | certificate | job | finance | admin | security');
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('sensitivity', 20)->default('personal')->comment('public | personal | sensitive');
            $table->boolean('is_mandatory')->default(false)->comment('لا يمكن تعطيله من التفضيلات (أمني/إداري)');
            $table->jsonb('default_channels')->default(new Expression("('[\"in_app\"]')"));
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->comment('FR-COM-001..006 — كتالوج أحداث الإشعارات');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_type_id')->constrained('notification_types')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->restrictOnDelete();
            $table->foreignId('language_id')->constrained('languages')->restrictOnDelete();
            $table->string('subject')->nullable();
            $table->text('body')->comment('متغيرات بصيغة {{ name }}');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['notification_type_id', 'channel_id', 'language_id', 'version'], 'notif_tpl_unique');
            $table->comment('قوالب الإشعارات لكل نوع × قناة × لغة');
        });

        // FR-COM-007: تفضيلات المستخدم (لا تتجاوز الإشعارات الإلزامية)
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 30);
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel_id']);
            $table->comment('FR-COM-007 — تفضيلات الإشعارات');
        });

        // Laravel database notifications (in-app) — القياسي
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->comment('إشعارات داخل التطبيق (Laravel database channel)');
        });

        // محاولات الإرسال لكل قناة (Delivery Attempt / Status)
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_uuid')->index()->comment('معرّف Notification في Laravel');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('notification_type_id')->nullable()->constrained('notification_types')->nullOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('status', 30)->default('queued')
                ->comment('queued | sent | delivered | failed | skipped_preference | skipped_sensitivity');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider', 50)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'channel_id']);
            $table->comment('FR-COM-008/009 — حالة كل محاولة إرسال');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notification_types');
        Schema::dropIfExists('notification_channels');
    }
};
