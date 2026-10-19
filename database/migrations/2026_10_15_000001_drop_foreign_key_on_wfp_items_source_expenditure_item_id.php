<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the `ON DELETE SET NULL` foreign key that
     * `wfp_items.source_expenditure_item_id` once carried.
     *
     * The column must NOT be a foreign key: its id has to SURVIVE its MEM line's
     * deletion, because that is how a regeneration still matches the generated
     * row and removes it (the line is gone, so its mapped row is gone). With the
     * constraint in place, re-importing a month — which replaces the month's
     * lines — silently NULLs the source id instead, and a row with a null source
     * id is, by definition, WFP-owned: the generator leaves it alone. The
     * result is ghost rows, and an exported WFP carrying targets the matrix no
     * longer has.
     *
     * The create-table migration was corrected to declare the column without the
     * constraint, but a database migrated before that correction still carries
     * it — migrations do not re-run — so it is dropped here.
     */
    public function up(): void
    {
        if (! Schema::hasTable('wfp_items') || ! $this->hasForeignKey()) {
            return;
        }

        Schema::table('wfp_items', function (Blueprint $table): void {
            $table->dropForeign(['source_expenditure_item_id']);
        });

        // Dropping the constraint can take its index with it; the column is
        // looked up by that id on every regeneration.
        if (! $this->isIndexed()) {
            Schema::table('wfp_items', function (Blueprint $table): void {
                $table->index('source_expenditure_item_id');
            });
        }
    }

    /**
     * Deliberately not restored: re-adding the constraint is what this
     * migration exists to undo.
     */
    public function down(): void {}

    private function hasForeignKey(): bool
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            // Every other supported schema is built fresh from the migrations,
            // which no longer declare the constraint.
            return false;
        }

        return $connection->table('information_schema.table_constraints')
            ->where('constraint_schema', $connection->getDatabaseName())
            ->where('table_name', 'wfp_items')
            ->where('constraint_name', 'wfp_items_source_expenditure_item_id_foreign')
            ->exists();
    }

    private function isIndexed(): bool
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return true;
        }

        return $connection->table('information_schema.statistics')
            ->where('table_schema', $connection->getDatabaseName())
            ->where('table_name', 'wfp_items')
            ->where('column_name', 'source_expenditure_item_id')
            ->exists();
    }
};
