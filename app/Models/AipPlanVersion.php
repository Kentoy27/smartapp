<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archived copy of an AIP's contents. Version 1 is the first submission;
 * every resubmission archives the revision it replaces as the next version,
 * so no submitted copy is ever overwritten or lost.
 *
 * The contents are stored as a JSON snapshot: an AIP is structured data (not
 * an uploaded file), so the version is the data itself rather than a path.
 */
class AipPlanVersion extends Model
{
    protected $fillable = [
        'aip_plan_id',
        'version_number',
        'status',
        'snapshot',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'snapshot' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AipPlan::class, 'aip_plan_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function label(): string
    {
        return 'Version '.$this->version_number;
    }

    /** The archived budget total, for the version list's summary line. */
    public function budgetTotal(): float
    {
        return round((float) ($this->snapshot['budget_total'] ?? 0), 2);
    }
}
