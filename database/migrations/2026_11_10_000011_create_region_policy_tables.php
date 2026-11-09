<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH — سياسات الوصول حسب المنطقة وسجل تغيير منطقة المستخدم.
 * FR-AUTH-033/034/035/036
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key', 80)->comment('مثل courses.free_access | payments.enabled | registration.allowed');
            $table->foreignId('country_id')->nullable()->constrained('countries')->cascadeOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->cascadeOnDelete();
            $table->jsonb('value')->comment('قيمة السياسة (bool/number/object)');
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // بديل NULLS NOT DISTINCT: مفتاح مولّد يعامل NULL كـ 0
            $table->string('scope_key', 120)->virtualAs("CONCAT(policy_key, ':', IFNULL(country_id,0), ':', IFNULL(region_id,0))");
            $table->unique('scope_key', 'region_policies_scope_unique');
            $table->index('policy_key');
            $table->comment('FR-AUTH-034/035 — سياسات الوصول حسب الدولة/المنطقة (country=null & region=null = افتراضي عام)');
        });

        Schema::create('user_region_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('old_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('old_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->foreignId('new_country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->foreignId('new_region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending | approved | rejected | applied');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->comment('FR-AUTH-036 — طلبات/سجل تغيير منطقة المستخدم');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_region_changes');
        Schema::dropIfExists('region_policies');
    }
};
