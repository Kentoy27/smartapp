<?php

namespace App\Notifications;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "OPCRF Returned" — sent to the staff member when a superadmin returns
 * their submission for revision. Carries the reviewer, their remarks and
 * the submission reference so the dashboard can point the user straight
 * at the Revise & Resubmit flow.
 */
class OpcrfReturned extends Notification
{
    use Queueable;

    public function __construct(
        public OpcrfSubmission $submission,
        public User $reviewer,
        public ?string $remarks,
    ) {}

    /**
     * Database channel — the app has no mail setup; the notification shows
     * in the user's dashboard banner (unread ones raise Action Required).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{submission_id: int, review_period: string, returned_by: string, returned_by_id: int, remarks: ?string, returned_at: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'review_period' => (string) $this->submission->review_period,
            'returned_by' => $this->reviewer->username,
            'returned_by_id' => $this->reviewer->id,
            'remarks' => $this->remarks,
            'returned_at' => optional($this->submission->updated_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
