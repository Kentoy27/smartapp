<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each OPCRF Part may be opened, and who changed that.
 *
 * Until this table existed, access was governed by a hard-coded month/day
 * window in config/opcrf.php — one lock for the whole form, set in code and
 * changed by a deploy. A superadmin now owns the calendar: one row per
 * (OPCRF year, Part), holding the exact window that Part is open for.
 *
 * Deliberately NOT the same thing as the MOV checklist's "Part 1/2/3"
 * (mov_parts): those are sections of the evidence checklist. These are the
 * Parts of the OPCRF form itself — the PART I..IV tabs of the workbook — and
 * the two vocabularies never mix.
 *
 * The window is stored as a pair of plain datetimes in the application's
 * configured timezone (config('app.timezone')), and every comparison against
 * "now" happens in that same zone, so the server, the database and the pages
 * all agree on whether a Part is open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcrf_schedules', function (Blueprint $table) {
            $table->id();

            // The OPCRF cycle this window belongs to. Not hardcoded anywhere:
            // the superadmin schedules any year, and the user's page reads the
            // year rather than assuming one.
            $table->unsignedSmallInteger('opcrf_year');

            // 1..4 — the Parts of the OPCRF form.
            $table->unsignedTinyInteger('part_number');

            // The access window. end_datetime may equal start_datetime only
            // when the superadmin deliberately makes a momentary window; the
            // ordering itself is enforced in the model's validation so the
            // rule reads the same everywhere.
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');

            // Optional instructions shown to the staff member on the locked /
            // open card ("Bring your signed WFP…").
            $table->text('description')->nullable();

            // The manual override: a superadmin can close a Part that is
            // inside its window (and reopen it) without deleting the dates.
            $table->boolean('is_enabled')->default(true);

            // Who last configured it. NullOnDelete keeps the schedule (and
            // therefore the calendar staff depend on) if an account is
            // removed.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // One window per Part per year. Multiple overlapping windows for
            // the same Part are not supported by the access rules below (a
            // Part is open if ANY of its windows is open), so allowing them
            // would let an "open" window hide inside a disabled one with no
            // way to tell which one the user was shown.
            $table->unique(['opcrf_year', 'part_number'], 'opcrf_schedule_year_part_unique');

            // The schedule list is always "this year's Parts, in Part order",
            // and "everything for one year" for the year switcher.
            $table->index('opcrf_year', 'opcrf_schedules_year_index');
        });

        /**
         * The audit trail for schedule changes.
         *
         * A deadline is something a staff member plans around, so "who moved
         * it, and from what to what" has to outlive the schedule row itself:
         * deleting a schedule keeps its log (opcrf_schedule_id is nullable
         * and not cascaded).
         *
         * The actor's NAME is copied at write time rather than joined in. A
         * renamed or deleted account must not rewrite history — the log has
         * to still say who did it.
         */
        Schema::create('opcrf_schedule_logs', function (Blueprint $table) {
            $table->id();

            // Null once the schedule it describes is deleted.
            $table->foreignId('opcrf_schedule_id')
                ->nullable()
                ->constrained('opcrf_schedules')
                ->nullOnDelete();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('actor_name');

            $table->unsignedSmallInteger('opcrf_year');
            $table->unsignedTinyInteger('part_number');

            // created / updated / enabled / disabled / deleted / extended
            $table->string('action', 20);

            // The before/after windows, so the log reads as a diff even after
            // the schedule row itself is gone.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Append-only: the row says when the change happened, and there
            // is no such thing as when it was last edited.
            $table->timestamp('created_at')->nullable();

            $table->index(['opcrf_year', 'part_number'], 'opcrf_schedule_logs_year_part_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcrf_schedule_logs');
        Schema::dropIfExists('opcrf_schedules');
    }
};