<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a generated WFP.
 *
 * A WFP line is month-grained and maps 1:1 to a MEM line, so the target lands
 * in the correct month by construction. The MEM-derived columns are refreshed
 * on every regeneration; the WFP-only columns (`major_output`,
 * `performance_indicator`) are never touched by the generator, so anything the
 * SH typed survives regeneration.
 *
 * `needs_review` / `review_fields` record which flaggable fields are still
 * empty. `refreshFlags()` recomputes them from the row's current values, so
 * filling a flagged field in the editor clears its flag on the next save.
 */
class WfpItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'wfp_plan_id',
        'source_expenditure_item_id',
        'source_matrix_id',
        'source_matrix_version',
        'group_key',
        'position',
        'month',
        'section',
        'objective',
        'program_activity',
        'major_output',
        'performance_indicator',
        'physical_target',
        'physical_unit',
        'financial_target',
        'account_title',
        'account_code',
        'funding_source',
        'organizational_classification',
        'activity_movs',
        'needs_review',
        'review_fields',
    ];

    protected $casts = [
        'source_matrix_version' => 'integer',
        'position' => 'integer',
        'month' => 'integer',
        'physical_target' => 'decimal:2',
        'financial_target' => 'decimal:2',
        'needs_review' => 'boolean',
        'review_fields' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WfpPlan::class, 'wfp_plan_id');
    }

    /** The MEM line this row was generated from (null once it is WFP-owned). */
    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(MonthlyExpenditureItem::class, 'source_expenditure_item_id');
    }

    public function sourceMatrix(): BelongsTo
    {
        return $this->belongsTo(MonthlyExpenditureMatrix::class, 'source_matrix_id');
    }

    /**
     * The fields the editor watches for review: a WFP-only pair that is empty
     * until the SH fills it in, plus the carried fields that flag when the MEM
     * left them blank.
     *
     * @return array<int, string>
     */
    public static function flaggableFields(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('mem.wfp_flag_on_generation', ['major_output', 'performance_indicator']),
            (array) config('mem.wfp_flag_when_empty', ['physical_target', 'funding_source', 'organizational_classification', 'activity_movs']),
        )));
    }

    /**
     * Recompute the review flags from the row's current values: every flaggable
     * field that is still empty is recorded, and `needs_review` follows.
     * Called after the SH edits a row, so a filled-in field drops its flag.
     */
    public function refreshFlags(): void
    {
        $missing = [];

        foreach (self::flaggableFields() as $field) {
            $value = $this->getAttribute($field);

            if ($value === null || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }

        $this->review_fields = $missing === [] ? null : $missing;
        $this->needs_review = $missing !== [];
    }

    /** Is this row's source matrix a different version than it was mapped from? */
    public function isStale(): bool
    {
        if ($this->source_matrix_id === null) {
            return false;
        }

        $matrix = $this->relationLoaded('sourceMatrix') ? $this->sourceMatrix : $this->sourceMatrix()->first();

        if ($matrix === null) {
            return false;
        }

        return (int) $this->source_matrix_version !== (int) $matrix->version;
    }
}
