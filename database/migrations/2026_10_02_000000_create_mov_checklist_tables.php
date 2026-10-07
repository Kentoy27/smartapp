<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MOV (Means of Verification) checklist.
 *
 * Three catalogue tables describe WHAT is required, in the shape the DepEd
 * checklist is written in — Part → Category (A/B/C) → MOV requirement — and
 * one table records what each staff member has attached to each requirement:
 *
 *   mov_parts ──< mov_categories ──< mov_requirements ──< user_movs >── users
 *
 * A user's document therefore always hangs off an exact requirement; the
 * names are never copied into the upload row, so a requirement that is
 * retitled or re-ordered cannot orphan what people uploaded against it.
 *
 * This is deliberately NOT the opcrf_movs table: that one holds evidence
 * files attached to a single OPCRF submission (one review = one set of
 * papers). This is the standing checklist every staff member fills and a
 * reviewer can accept or return, independent of any one submission.
 *
 * user_movs carries the review columns (status, remarks, reviewed_by,
 * reviewed_at) from the start, so accepting/returning a document later is
 * a workflow change, not a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mov_parts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('part_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('mov_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mov_part_id')->constrained('mov_parts')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('category_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mov_part_id', 'name']);
        });

        Schema::create('mov_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mov_category_id')->constrained('mov_categories')->cascadeOnDelete();
            // The MOV's own number inside its category (1, 2, 3 …). Stored as
            // an integer so it sorts and is searched numerically, and rendered
            // as "MOV 1" by the model.
            $table->unsignedSmallInteger('mov_number');
            $table->string('title');
            $table->text('description')->nullable();
            // Optional items are shown, but never counted towards the
            // completion bar at the top of the page.
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('display_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mov_category_id', 'mov_number']);
        });

        Schema::create('user_movs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mov_requirement_id')->constrained('mov_requirements')->cascadeOnDelete();
            // The file as the person uploaded it (for display and download)…
            $table->string('original_name');
            // …and where it actually lives on the local disk. Never a public
            // path: downloads go through a route that checks ownership.
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status')->default('uploaded');
            $table->text('remarks')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One current document per requirement: "Replace" overwrites this
            // row rather than accumulating versions the checklist cannot show.
            $table->unique(['user_id', 'mov_requirement_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_movs');
        Schema::dropIfExists('mov_requirements');
        Schema::dropIfExists('mov_categories');
        Schema::dropIfExists('mov_parts');
    }
};
