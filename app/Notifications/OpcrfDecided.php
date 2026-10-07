<?php

namespace App\Notifications;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "OPCRF Approved" — sent to the staff member when a reviewer marks their
 * submission compliant. The workflow may then route it onward to the next
 * superadmin for final approval, so the wording says "marked compliant"
 * rather than promising the cycle is finished; the staff table still shows
 * the live status.
 */
class OpcrfDecided extends Notification
{
    use Queueable;

    public function __construct(
        public OpcrfSubmission $submission,
        public User $reviewer,
        public ?string $remarks,
        public bool $forwardedOnward,
    ) {}

    /**
     * Database channel — the app has no mail setup; this shows in the
     * topbar notification centre.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{submission_id: int, review_period: string, decided_by: string, remarks: ?string, forwarded_onward: bool, decided_at: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'review_period' => (string) $this->submission->review_period,
            'decided_by' => $this->reviewer->username,
            'remarks' => $this->remarks,
            'forwarded_onward' => $this->forwardedOnward,
            'decided_at' => now()->toIso8601String(),
        ];
    }
}
