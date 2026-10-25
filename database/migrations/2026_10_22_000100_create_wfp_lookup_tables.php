<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The WFP lookup library: the small, administrator-managed master lists a
     * Monthly Expenditure Matrix line and an Activity Template draw their
     * reusable values from.
     *
     * Four categories get their own table rather than one shared "lookup"
     * table, so a template's `unit_id` can only ever point at a unit and the
     * database itself refuses a cross-category reference.
     *
     * Account titles / codes / object codes deliberately have NO table here:
     * that master data already exists as `account_classifications` (the RCA /
     * UACS reference table), and a second copy would be a conflicting
     * definition. Line types (operations / project) are fixed by the form
     * (config/mem.php `sections`), so they are not administrable either.
     *
     * Conventions, all matching `account_classifications`:
     * - Every code is a string on purpose: codes can carry leading zeroes,
     *   which a numeric column would silently mangle.
     * - Rows are retired with `is_active = false`, never hard-deleted, so a
     *   historical line or template that points at one stays readable.
     * - `created_by` / `updated_by` record the administrator behind a change.
     */
    public function up(): void
    {
        Schema::create('wfp_funding_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('code');
            $table->index('name');
            $table->index('is_active');
        });

        Schema::create('wfp_org_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('code');
            $table->index('name');
            $table->index('is_active');
        });

        Schema::create('wfp_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            // "ream", "pc", "set" — the short form the form shows.
            $table->string('abbreviation', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('code');
            $table->index('name');
            $table->index('is_active');
        });

        Schema::create('wfp_resource_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('code');
            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_resource_kinds');
        Schema::dropIfExists('wfp_units');
        Schema::dropIfExists('wfp_org_classifications');
        Schema::dropIfExists('wfp_funding_sources');
    }
};
