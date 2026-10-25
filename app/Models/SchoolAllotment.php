<?php

namespace App\Models;

use App\Support\WfpAllotment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school's annual allotment budget, set by the Budget office.
 *
 * The amount is what the School Head may consume through the school's
 * Monthly Expenditure Matrices for that calendar year. Consumption is never
 * stored here: it is derived from the matrices' own lines (see
 * {@see WfpAllotment}), so the remaining balance can never go
 * stale against an edit in the matrix editor.
 *
 * The certification is the Budget office's statement that the amount is
 * available. It belongs to the same row because it certifies that exact
 * amount: the manager clears it whenever the amount changes.
 */
class SchoolAllotment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'year',
        'amount',
        'set_by',
        'certified_by',
        'certified_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'amount' => 'decimal:2',
        'certified_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** The account that last set the amount. */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** The Budget account that certified the amount as available. */
    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    /**
     * Whether this amount carries a valid certification. Both halves must be
     * present: a row whose certifier account was deleted has a date but no
     * one who stands behind it, which is not a certificate.
     */
    public function isCertified(): bool
    {
        return $this->certified_by !== null && $this->certified_at !== null;
    }
}
