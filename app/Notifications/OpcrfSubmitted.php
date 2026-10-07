<?php

namespace App\Notifications;

use App\Models\OpcrfSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "OPCRF Submitted" — sent to the superadmin a staff member routed their
 * OPCR to, the moment the submission is confirmed. Without it the reviewer's
 * queue only changes when they happen to open Review Opcrf; the bell in the
 * topbar is what tells them work arrived.
 */
class OpcrfSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public OpcrfSubmission $submission,
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
     * @return array{submission_id: int, review_period: string, employee: string, submitted_at: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'submission_id' => $this->submission->id,
            'review_period' => (string) $this->submission->review_period,
            // The name written on the form is what the reviewer will recognise
            // from the queue; the account name is the fallback for a
            // submission whose form left it blank.
            'employee' => $this->employeeName(),
            'submitted_at' => $this->submission->submitted_at?->toIso8601String(),
        ];
    }

    private function employeeName(): string
    {
        $onForm = trim((string) $this->submission->employee_name);

        if ($onForm !== '') {
            return $onForm;
        }

        return $this->submission->realNameLabel() !== ''
            ? $this->submission->realNameLabel()
            : (string) $this->submission->user?->username;
    }
}
