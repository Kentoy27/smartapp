<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a submission's review history: a superadmin's action
 * (compliance/approve, forward, return-for-revision) with their remarks and
 * the routing (from → to). Rows are immutable — the trail is the record.
 *
 * Actions:
 *   compliance — the reviewer approved/complied the submission (status →
 *                'for_compliance', i.e. compliant as reviewed). If the
 *                workflow requires it, the compliant submission is then
 *                routed onward to the next superadmin (SY).
 *   forward    — handed to the next superadmin without a final decision
 *                (status stays/becomes 'forwarded').
 *   return     — sent back to the staff member for revision (status →
 *                'returned').
 *   resubmit   — the staff member's revised submission, recorded by the
 *                system (reviewer null; status 'returned' → 'resubmitted').
 */
class OpcrfReview extends Model
{
    public const ACTION_COMPLIANCE = 'compliance';

    public const ACTION_FORWARD = 'forward';

    public const ACTION_RETURN = 'return';

    public const ACTION_RESUBMIT = 'resubmit';

    protected $fillable = [
        'opcrf_submission_id',
        'reviewer_id',
        'action',
        'remarks',
        'from_id',
        'to_id',
        'previous_status',
        'new_status',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(OpcrfSubmission::class, 'opcrf_submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_id');
    }

    /**
     * Human label for the action column.
     */
    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_COMPLIANCE => 'Compliance / Approved',
            self::ACTION_FORWARD => 'Forwarded',
            self::ACTION_RETURN => 'Returned for Revision',
            self::ACTION_RESUBMIT => 'Resubmitted',
            default => ucfirst($this->action),
        };
    }

    /**
     * The recorded status transition for this action (e.g. "Pending Review →
     * Returned for Revision"), or null when the row predates the columns or
     * carries no statuses.
     */
    public function transitionLabel(): ?string
    {
        if ($this->previous_status === null && $this->new_status === null) {
            return null;
        }

        return OpcrfSubmission::statusLabel((string) $this->previous_status)
            .' → '
            .OpcrfSubmission::statusLabel((string) $this->new_status);
    }

    /**
     * The routing shown in the history table's From → To column: usernames
     * when known, "staff member" for a return, an em dash when unset.
     */
    public function routeLabel(): string
    {
        $to = $this->to?->username;

        if ($to === null && $this->action === self::ACTION_RETURN) {
            $to = 'staff member';
        }

        return ($this->from?->username ?? '—').' → '.($to ?? '—');
    }
}
