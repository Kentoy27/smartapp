<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class OpcrfSubmission extends Model
{
    protected $fillable = [
        'user_id',
        'employee_name',
        'position',
        'review_period',
        'division_office',
        'objectives',
        'accomplishments',
        'self_rating',
        'remarks',
        'file_path',
        'file_original_name',
        'file_updated_at',
        'approved_at',
        'approved_by',
        'reviewer_id',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'file_updated_at' => 'datetime',
        'approved_at' => 'datetime',
        'self_rating' => 'float',
    ];

    /**
     * Does this submission carry an archived copy of the staff member's
     * uploaded .xlsx file?
     */
    public function hasFile(): bool
    {
        return $this->file_path !== null
            && Storage::disk('local')->exists($this->file_path);
    }

    /**
     * The name the browser saves this submission's workbook under: the staff
     * member's real name from their account, then a plain "OPCR" — the
     * template wording the file was created from ("OPCRF-TEMPLATE.xlsx") is
     * not repeated, and neither is whatever a reviewer renamed the approved
     * copy to, so a reviewer's download is always "Real Name - OPCRF.xlsx".
     * Only the name changes; the bytes are the staff member's original file,
     * untouched.
     */
    public function fileDownloadName(): string
    {
        $realName = $this->realNameLabel();

        // No real name on the account: keep the id so several downloads from
        // unnamed accounts still tell each other apart.
        return $realName === ''
            ? 'OPCR-submission-'.$this->id.'.xlsx'
            : $realName.' - OPCRF.xlsx';
    }

    /**
     * The owner's real name (their account name, not whatever was typed into
     * the workbook), made safe for a file name: path separators and the
     * characters Windows rejects are dropped, whitespace collapsed, length
     * capped. Empty when the account has no name.
     */
    public function realNameLabel(): string
    {
        $name = (string) ($this->user?->name ?? '');

        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';

        return mb_substr(trim($name), 0, 60);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The superadmin who approved this submission (null while it waits for
     * review).
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The superadmin this submission was sent to — the staff member picks
     * the recipient when submitting, and only that account reviews it.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * Submissions a superadmin may review: the ones routed to them, plus any
     * unassigned (sent before routing existed, or whose recipient's account
     * was deleted) so a record is never stranded where nobody can see it.
     */
    public function scopeVisibleTo(Builder $query, User $reviewer): Builder
    {
        return $query->where(function (Builder $routed) use ($reviewer): void {
            $routed->where('reviewer_id', $reviewer->id)
                ->orWhereNull('reviewer_id');
        });
    }

    /**
     * Has the superadmin reviewed and approved this submission?
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * May this user handle the submission at all — its evidence (MOVs),
     * its review, its workbook?
     *
     * The owning staff member always can; a superadmin only for the
     * submissions routed to them (or unassigned ones).
     */
    public function canBeReviewedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->is_superadmin) {
            return $this->reviewer_id === null || $this->reviewer_id === $user->id;
        }

        return $this->user_id === $user->id;
    }

    /**
     * Can the given user download this submission's workbook?
     *
     * A superadmin may fetch the ones routed to them at any time (that is
     * the review); the owning staff member only once it is approved — from
     * then on the stored file is the official approved copy.
     */
    public function canBeDownloadedBy(?User $user): bool
    {
        if (! $this->canBeReviewedBy($user)) {
            return false;
        }

        return $user->is_superadmin || $this->isApproved();
    }

    /**
     * Hand the submission to another superadmin.
     *
     * Only the current recipient can do this — the owner has no say, and a
     * superadmin the submission was never routed to cannot see it at all. The
     * archived workbook stays (the new recipient reviews the very same
     * document), but a completed approval is **cleared**: the new recipient
     * reviews, updates and approves it themselves.
     *
     * @return bool false when the recipient is the current one
     */
    public function forwardTo(User $recipient): bool
    {
        if (! $recipient->is_superadmin || $recipient->id === $this->reviewer_id) {
            return false;
        }

        $this->reviewer_id = $recipient->id;
        $this->approved_at = null;
        $this->approved_by = null;
        $this->save();

        return true;
    }

    /**
     * The superadmin's review outcome: mark the submission approved.
     *
     * When a corrected/filled workbook is supplied it replaces the archived
     * file as the official copy — every later download serves the approved
     * workbook — and the superseded file is removed from storage. Called
     * without one, the submission is approved as submitted.
     */
    public function approve(User $reviewer, ?string $storedPath = null, ?string $originalName = null): void
    {
        $superseded = null;

        if ($storedPath !== null) {
            $superseded = $this->file_path;

            $this->file_path = $storedPath;
            $this->file_original_name = $originalName;
            $this->file_updated_at = now();
        }

        $this->approved_at = now();
        $this->approved_by = $reviewer->id;
        $this->save();

        // Only after the row points at the new file: never delete the copy
        // a submission would still be serving.
        if ($superseded !== null && $superseded !== $storedPath) {
            Storage::disk('local')->delete($superseded);
        }
    }

    /**
     * Evidence documents (Means of Verification) attached to this form.
     */
    public function movs(): HasMany
    {
        return $this->hasMany(OpcrfMov::class)->oldest('id');
    }

    /**
     * Can the given user manage (upload/delete) MOVs on this submission?
     * Only the owning staff member — superadmins get their own tooling.
     */
    public function canBeManagedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    protected static function booted(): void
    {
        // Deleting a submission takes its whole record with it: every MOV row
        // (each removes its own stored file) and the archived workbook — the
        // file IS the submission, not a cache. Done per model on purpose: the
        // database cascade on opcrf_movs would drop the rows *without* firing
        // their hooks and leave the files on disk.
        static::deleting(function (self $submission): void {
            $submission->movs()->get()->each->delete();

            if ($submission->file_path !== null) {
                Storage::disk('local')->delete($submission->file_path);
            }
        });
    }
}
