<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A MOV holds a SET of pictures, not one document.
 *
 * The first cut allowed exactly one document per requirement, which meant a
 * MOV evidenced by several photographs (a five-page agreement, a plan and
 * its annexes) had to overwrite itself to keep the last one. Pictures are
 * now the evidence: a MOV accumulates as many as the staff member needs,
 * with no cap on the count, and the page shows them as a gallery.
 *
 * The unique constraint is dropped, not replaced by anything else — the same
 * (user, requirement) pair may now have many rows, and the listing order is
 * the insertion order. Existing rows are untouched; nothing is deleted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_movs', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'mov_requirement_id']);
            $table->index(['user_id', 'mov_requirement_id'], 'user_movs_user_requirement_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_movs', function (Blueprint $table) {
            $table->dropIndex('user_movs_user_requirement_index');
            $table->unique(['user_id', 'mov_requirement_id']);
        });
    }
};
