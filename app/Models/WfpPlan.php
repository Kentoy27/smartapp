<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Work and Financial Plan for one school and year — generated from that
 * school's Monthly Expenditure Matrices and reconciled in place.
 *
 * Out-of-date is DERIVED, never stored: the plan is out of date when any of its
 * lines was mapped from a MEM version that is no longer the matrix's current
 * version (see `isOutOfDate()` / `staleSources()`).
 */
class WfpPlan extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'school_id',
        'year',
        'template_version',
        'status',
        'generated_by',
        'approved_by',
        'generated_at',
        'approved_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WfpItem::class, 'wfp_plan_id')->orderBy('position')->orderBy('id');
    }

    public function fiscalYearLabel(): string
    {
        return 'CY '.$this->year;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    /** Only the SH of this school may manage it. */
    public function managedBy(?User $user): bool
    {
        return $user !== null
            && $user->canAccessWfp()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id;
    }

    /**
     * The annual financial target: the sum of every line's financial target,
     * rounded to two decimals (SQLite hands back a float from sum(), so the
     * rounding happens here rather than at the database).
     */
    public function annualFinancialTotal(): float
    {
        return round((float) $this->items()->sum('financial_target'), 2);
    }

    /** How many lines still carry an unreviewed flag. */
    public function flaggedCount(): int
    {
        return $this->items()->where('needs_review', true)->count();
    }

    /**
     * Is the plan out of date? True when any line's recorded source version no
     * longer matches its matrix's current version.
     */
    public function isOutOfDate(): bool
    {
        foreach ($this->items()->with('sourceMatrix')->get() as $item) {
            if ($item->isStale()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The per-matrix breakdown behind the out-of-date banner: each source matrix
     * that has moved on, with how many lines are affected and the versions
     * involved.
     *
     * @return array<int, array{matrix: MonthlyExpenditureMatrix, items: int, was: int, now: int}>
     */
    public function staleSources(): array
    {
        $sources = [];

        foreach ($this->items()->with('sourceMatrix')->get() as $item) {
            $matrix = $item->sourceMatrix;

            if ($matrix === null || ! $item->isStale()) {
                continue;
            }

            $id = (int) $matrix->id;

            if (! isset($sources[$id])) {
                $sources[$id] = [
                    'matrix' => $matrix,
                    'items' => 0,
                    'was' => (int) $item->source_matrix_version,
                    'now' => (int) $matrix->version,
                ];
            }

            $sources[$id]['items']++;
        }

        return array_values($sources);
    }
}
