<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An immutable history of the Superadmin's final decisions on a WFP.
     *
     * Each row is one decision — approved or returned for revision — with
     * who made it, when, and the remarks that justified it. The current
     * state lives on `wfp_plans.status`; this table is the audit trail and
     * the source of the printed SDS signature. `decided_by` is nullable so
     * deleting an account does not erase the history; the exporter treats a
     * missing actor as "no signature to print" rather than inventing one.
     */
    public function up(): void
    {
        Schema::create('wfp_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wfp_plan_id')->constrained('wfp_plans')->cascadeOnDelete();
            $table->string('decision', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['wfp_plan_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_approvals');
    }
};
