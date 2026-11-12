<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ORG — المؤسسات والعضويات والدعوات.
 * FR-ORG-001/002/005/006/007 · FR-AUTH-015
 * BR-ORG-002: العضوية لا تمنح صلاحيات إدارية — مسؤول المؤسسة = دور spatie organization_admin بنطاق access_scopes(type=organization)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->jsonb('name');
            $table->string('slug', 150)->unique();
            $table->string('type', 30)->comment('educational | training_center | ngo | employer | donor | government | partner | other — FR-ORG-005');
            $table->string('status', 20)->default('pending')->index()->comment('pending | active | suspended | archived');
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('registration_number', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->jsonb('description')->nullable();
            $table->jsonb('settings')->default(new Expression("('{}')"));

            // FR-ORG-007: نقطة تكامل مستقبلية مع جهات توظيف دولية
            $table->string('external_source', 50)->nullable();
            $table->string('external_ref', 150)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['country_id', 'region_id']);
            $table->unique(['external_source', 'external_ref']);
            $table->comment('FR-ORG-001/005/007 — الحسابات المؤسسية');
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('membership_type', 30)
                ->comment('member | staff | learner | instructor | employer_representative | partner_contact | volunteer — FR-ORG-006');
            $table->string('status', 20)->default('active')->comment('pending | active | suspended | left | removed');
            $table->string('title', 150)->nullable()->comment('المسمى داخل المؤسسة');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->comment('FR-AUTH-015 · FR-ORG-002/006 — عضوية المستخدم في المؤسسة');
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('membership_type', 30);
            $table->foreignId('role_id')->nullable()->constrained(config('permission.table_names.roles'))->nullOnDelete()
                ->comment('دور مقترح يُسند عند القبول (يخضع لنفس قواعد منع التصعيد)');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 20)->default('pending')->comment('pending | accepted | expired | revoked');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->comment('FR-ORG-002 — دعوات الانضمام للمؤسسة');
        });

        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT org_inv_contact_required CHECK (email IS NOT NULL OR phone IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
