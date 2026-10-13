<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an approved AIP to the MOV evidence that backs it.
 *
 * The MOV checklist's "Approved Work and Financial Plan (WFP) and Annual
 * Implementation Plan (AIP)" requirement is satisfied by a document. When an
 * AIP is approved, the owner may attach the generated AIP workbook to that
 * requirement: the attachment is a normal user_movs row (so it appears in the
 * existing checklist, inherits its review state and its download route), and
 * `aip_plan_id` records which plan produced it.
 *
 * The column is nullable and nullOnDelete: retiring a plan detaches the
 * evidence file rather than deleting the person's upload row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_movs', function (Blueprint $table) {
            $table->foreignId('aip_plan_id')
                ->nullable()
                ->after('mov_requirement_id')
                ->constrained('aip_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_movs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aip_plan_id');
        });
    }
};
