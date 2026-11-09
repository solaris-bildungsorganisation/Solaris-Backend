<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN — المؤشرات المجمّعة، تصدير التقارير، قصص النجاح.
 * FR-ADMIN-001..010/012 · FR-ORG-004
 * المؤشرات التعليمية/التوظيفية تُغذّى من جداول الموجات 2-5؛ في الموجة 1 نبني الإطار + مؤشرات المستخدمين والمؤسسات.
 */
return new class extends Migration
{
    public function up(): void
    {
        // FR-ADMIN-002..006/008/010 · FR-ORG-004: لقطات مؤشرات مجمّعة (بدون بيانات شخصية — BR-WOMEN-002)
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('metric_key', 80)->comment('users.registered | users.active | orgs.active | enrollments.completed ...');
            $table->string('period_type', 10)->comment('day | week | month | quarter | year');
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->jsonb('dimensions')->default(new Expression("('{}')"))->comment('أبعاد إضافية (program_id لاحقًا...)');
            $table->string('dimensions_hash', 64)->default('');
            $table->decimal('value', 18, 4);
            $table->timestamp('computed_at')->useCurrent();

            $table->string('snapshot_key', 255)->virtualAs(
                "CONCAT_WS(':', metric_key, period_type, period_start, IFNULL(country_id,0), IFNULL(region_id,0), IFNULL(organization_id,0), dimensions_hash)"
            );
            $table->unique('snapshot_key', 'metric_snapshots_unique');
            $table->index(['metric_key', 'period_type', 'period_start']);
            $table->comment('FR-ADMIN-002..010 · FR-ORG-004 — مؤشرات مجمّعة');
        });

        // FR-ADMIN-012: تصدير التقارير (Job في Horizon)
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report_key', 80)->comment('users | organizations | learning | donors ...');
            $table->jsonb('filters')->default(new Expression("('{}')"))->comment('country/region/program/period — FR-ADMIN-010');
            $table->string('format', 10)->default('xlsx')->comment('xlsx | csv | pdf');
            $table->string('status', 20)->default('queued')->comment('queued | processing | completed | failed | expired');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('context_id')->nullable()->constrained('contexts')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete()
                ->comment('نطاق التصدير — لا يتجاوز صلاحيات المستخدم');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->comment('FR-ADMIN-012 — طلبات تصدير التقارير');
        });

        // FR-ADMIN-007: قصص النجاح (تتطلب موافقة صاحب القصة)
        Schema::create('success_stories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->jsonb('title');
            $table->jsonb('summary')->nullable();
            $table->jsonb('body');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('consent_status', 20)->default('pending')->comment('not_required | pending | granted | withdrawn');
            $table->timestamp('consent_recorded_at')->nullable();
            $table->foreignId('consent_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft')->comment('draft | in_review | published | archived');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->comment('FR-ADMIN-007 — قصص النجاح');
        });
        DB::statement("ALTER TABLE success_stories ADD CONSTRAINT success_stories_publish_requires_consent CHECK (status <> 'published' OR consent_status IN ('granted','not_required'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('success_stories');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('metric_snapshots');
    }
};
