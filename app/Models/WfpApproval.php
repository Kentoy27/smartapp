<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One decision in a WFP's final-approval history.
 *
 * The plan's status is the current state; these rows are the audit trail.
 * They are append-only: a decision is recorded once and never edited, so the
 * printed sign-off can always be traced back to the person and moment that
 * produced it.
 */
class WfpApproval extends Model
{
    public const DECISION_APPROVED = 'approved';

    public const DECISION_RETURNED = 'returned';

    protected $table = 'wfp_approvals';

    protected $fillable = [
        'wfp_plan_id',
        'decision',
        'decided_by',
        'remarks',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WfpPlan::class, 'wfp_plan_id');
    }

    /** The account that made the decision; null after that account is deleted. */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isApproved(): bool
    {
        return $this->decision === self::DECISION_APPROVED;
    }

    public function decisionLabel(): string
    {
        return $this->isApproved() ? 'Approved' : 'Returned for Revision';
    }
}
