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
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'calculated_total' => 'decimal:2',
    ];

    public function matrix(): BelongsTo
    {
        return $this->belongsTo(MonthlyExpenditureMatrix::class, 'matrix_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class, 'account_classification_id');
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
