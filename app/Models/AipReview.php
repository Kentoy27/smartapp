<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in an AIP's review history: an action (submit, compliance/
 * approve, return-for-revision, resubmit) with its actor, remarks and the
 * status transition. Rows are immutable — the trail is the record.
 *
 * The actor for every action lives in `reviewer_id` (the same convention the
 * OPCRF review trail uses): the reviewer for a review action, the owner for a
 * submission/resubmission.
 */
class AipReview extends Model
{
    public const ACTION_SUBMIT = 'submit';

    public const ACTION_COMPLIANCE = 'compliance';

    public const ACTION_RETURN = 'return';

    public const ACTION_RESUBMIT = 'resubmit';

    protected $fillable = [
        'aip_plan_id',
        'reviewer_id',
        'action',
        'previous_status',
        'new_status',
        'remarks',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AipPlan::class, 'aip_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMIT => 'Submitted for Review',
            self::ACTION_COMPLIANCE => 'Approved',
            self::ACTION_RETURN => 'Returned for Revision',
            self::ACTION_RESUBMIT => 'Resubmitted for Review',
            default => ucfirst((string) $this->action),
        };
    }

    /** The recorded transition ("Draft → Pending Review"), or null. */
    public function transitionLabel(): ?string
    {
        if ($this->previous_status === null && $this->new_status === null) {
            return null;
        }

        return AipPlan::statusLabel((string) $this->previous_status)
            .' → '
            .AipPlan::statusLabel((string) $this->new_status);
    }
}
