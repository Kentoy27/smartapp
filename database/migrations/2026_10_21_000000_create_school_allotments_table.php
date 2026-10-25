<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The allotment budget the Budget office sets for one school's School
     * Head for one calendar year.
     *
     * It belongs to the SCHOOL, not to the account: the Monthly Expenditure
     * Matrices it is consumed by belong to the school, so a change of School
     * Head does not strand the allotment (the same rule the matrices and AIPs
     * follow). One allotment per school per year.
     */
    public function up(): void
    {
        Schema::create('school_allotments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'year']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_allotments');
    }
};
