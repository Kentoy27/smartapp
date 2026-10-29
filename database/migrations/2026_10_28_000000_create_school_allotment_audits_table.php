<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only trail of every change to a school's annual allotment
     * amount (SET / CLEAR), with the amount that was on the row before and
     * after, and whether the change came from the single-row Save or a bulk
     * apply (`source`).
     *
     * `school_id` is a plain indexed column, not a foreign key: an audit row
     * must outlive the school (and the allotment it describes), so it must not
     * be cascaded away. The row's own id is deliberately not referenced: a
     * cleared allotment is deleted, and a later re-set creates a new one, so
     * the audit is keyed by what the entry is about — school and year.
     */
    public function up(): void
    {
        Schema::create('school_allotment_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->integer('year');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 10);
            $table->decimal('previous_amount', 15, 2)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('source', 12);
            $table->timestamp('created_at')->nullable();

            $table->index(['school_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_allotment_audits');
    }
};
