<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only trail of every administrative change to a classification
     * (CREATE / UPDATE / DELETE / ACTIVATE / DEACTIVATE), with the values
     * before and after.
     *
     * `account_classification_id` is a plain indexed column, not a foreign key:
     * the audit row must outlive the classification it describes, so it must
     * not be cascaded away if a record is ever hard-deleted.
     */
    public function up(): void
    {
        Schema::create('account_classification_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_classification_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('account_classification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_classification_audits');
    }
};
