<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per personalised OPCRF template handed to a staff member.
 *
 * The workbook the user downloads is generated for them — their name, their
 * position, their school and division are written into the header block —
 * so which file a submission should be judged against has to be knowable
 * after the fact. This is that record: who it was generated for, which
 * version of the template it came from, when it was generated, and the file
 * name the browser was given.
 *
 * The generated file itself is NOT stored. It is reproducible from the
 * shipped template plus the account at any time (OpcrfTemplatePersonalizer),
 * and keeping a copy of every download would be a second copy of a form
 * nobody has filled in yet. Only submissions archive the bytes that matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opcrf_templates', function (Blueprint $table) {
            $table->id();

            // The account the template was personalized for.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which revision of the official template produced it, so a
            // submission can say what form it was answered on.
            $table->string('version', 20);

            // The file name the browser was given — the real registered
            // name, made safe. Recorded for the dashboard's status panel and
            // for an administrator tracing a submission back to its form.
            $table->string('filename');

            // 1 for the first download, +1 each time the user asks again.
            // An account's latest row is its current template.
            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            $table->index(['user_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opcrf_templates');
    }
};
