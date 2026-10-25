<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only trail of every administrative change to the WFP lookup
     * library and its activity templates (CREATE / UPDATE / ACTIVATE /
     * DEACTIVATE), with the values before and after.
     *
     * One table for every lookup category: `auditable_type` carries the
     * category key ("funding_source", "unit", …, "line_template") and
     * `auditable_id` the row's id. Both are plain indexed columns, not
     * foreign keys: an audit row must outlive the record it describes, so it
     * must not be cascaded away if a row is ever hard-deleted.
     */
    public function up(): void
    {
        Schema::create('wfp_lookup_audits', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type', 40);
            $table->unsignedBigInteger('auditable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wfp_lookup_audits');
    }
};
