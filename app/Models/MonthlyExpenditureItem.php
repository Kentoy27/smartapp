<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expenditure line inside a Monthly Expenditure Matrix.
 *
 * `calculated_total` is ALWAYS recomputed server-side from quantity × unit cost
 * (`recalculateTotal()`), never trusted from the client: the editor may show a
 * live running total, but what is stored is what the server calculated.
 *
 * The `*_id` lookup columns record which administrator-managed WFP Setup row a
 * line was encoded from. The matching text columns remain the line's snapshot:
 * reports and exports read the text, so retiring or renaming a lookup never
 * rewrites a saved historical line.
 */
class MonthlyExpenditureItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'matrix_id',
        'position',
        'section',
        'objective',
        'activity_description',
        'resource_kind',
        'quantity',
        'unit',
        'unit_cost',
        'calculated_total',
        'account_title',
        'account_code',
        'account_classification_id',
        'funding_source',
        'organizational_classification',
        'activity_movs',
        'major_output',
        'performance_indicator',
        'wfp_line_template_id',
        'resource_kind_id',
        'unit_id',
        'funding_source_id',
        'org_classification_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'calculated_total' => 'decimal:2',
        'resource_kind_id' => 'integer',
        'unit_id' => 'integer',
        'funding_source_id' => 'integer',
        'org_classification_id' => 'integer',
    ];

    public function matrix(): BelongsTo
    {
        return $this->belongsTo(MonthlyExpenditureMatrix::class, 'matrix_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class, 'account_classification_id');
    }

    public function resourceKindRecord(): BelongsTo
    {
        return $this->belongsTo(WfpResourceKind::class, 'resource_kind_id');
    }

    public function unitRecord(): BelongsTo
    {
        return $this->belongsTo(WfpUnit::class, 'unit_id');
    }

    public function fundingSourceRecord(): BelongsTo
    {
        return $this->belongsTo(WfpFundingSource::class, 'funding_source_id');
    }

    public function orgClassificationRecord(): BelongsTo
    {
        return $this->belongsTo(WfpOrgClassification::class, 'org_classification_id');
    }

    public function lineTemplate(): BelongsTo
    {
        return $this->belongsTo(WfpLineTemplate::class, 'wfp_line_template_id');
    }

    /**
     * Quantity × unit cost, rounded to two decimals. Null when either factor is
     * missing — an incomplete line has no total rather than a wrong zero.
     */
    public function recalculateTotal(): ?float
    {
        if ($this->quantity === null || $this->unit_cost === null) {
            return null;
        }

        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }

    /**
     * Recompute and store the total. Returns the stored value.
     */
    public function syncTotal(): ?float
    {
        $total = $this->recalculateTotal();
        $this->calculated_total = $total;

        return $total;
    }
}
