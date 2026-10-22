<?php

namespace App\Notifications;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent to the staff member when a superadmin approves their OPCRF submission.
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
     * @return array{submission_id: int, review_period: string, decided_by: string, decided_by_name: string, remarks: ?string, forwarded_onward: bool, decided_at: string}
     */
    public function toArray(object $notifiable): array
    {
        $reviewerName = trim((string) $this->reviewer->name);

        return [
            'submission_id' => $this->submission->id,
            'review_period' => (string) $this->submission->review_period,
            'decided_by' => $this->reviewer->username,
            'decided_by_name' => $reviewerName !== '' ? $reviewerName : $this->reviewer->username,
            'remarks' => $this->remarks,
            'forwarded_onward' => $this->forwardedOnward,
            'decided_at' => now()->toIso8601String(),
        ];
    }
}
