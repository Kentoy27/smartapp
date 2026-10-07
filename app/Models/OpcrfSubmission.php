<?php

namespace App\Models;

use App\Notifications\OpcrfReturned;
use App\Support\OpcrfTemplatePersonalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class OpcrfSubmission extends Model
{
    /**
     * The review-workflow statuses. 'pending' — waiting for the reviewer it
     * is assigned to; 'forwarded' — auto-routed onward by a compliance mark
     * (Eve → SY): the original reviewer still sees it, the assignee holds
     * the review step;
     * 'for_compliance' — compliant/approved by the reviewer (and routed
     * onward when the workflow requires it); 'returned' — back with the
     * staff member for revision; 'resubmitted' — revised by the staff
     * member and waiting for review again; 'approved' — final approval.
     * The legacy approved_at/approved_by columns keep working alongside
     * 'approved'.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_FORWARDED = 'forwarded';

    public const STATUS_FOR_COMPLIANCE = 'for_compliance';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_RESUBMITTED = 'resubmitted';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'user_id',
        'opcrf_part',
        'reference',
        'opcrf_template_id',
        'employee_name',
        'upload_check',
        'account_name',
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
        'assigned_to',
        'status',
        'submitted_at',
    ];

    protected $casts = [
        'opcrf_part' => 'integer',
        'submitted_at' => 'datetime',
        'file_updated_at' => 'datetime',
        'approved_at' => 'datetime',
        'self_rating' => 'float',
    ];

    /**
     * The OPCRF Part this submission answers. Everything filed before Parts
     * were schedulable belongs to Part 1, which is also the column default —
     * so historical rows already read correctly and nothing is backfilled.
     */
    public const PART_ONE = 1;

    public const PART_TWO = 2;

    public const PART_THREE = 3;

    public const PART_FOUR = 4;

    /**
     * Human label for a workflow status (badges, staff table, review modal).
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending Review',
            self::STATUS_FORWARDED => 'Forwarded',
            self::STATUS_FOR_COMPLIANCE => 'For Compliance',
            self::STATUS_RETURNED => 'Returned for Revision',
            self::STATUS_RESUBMITTED => 'Resubmitted for Review',
            self::STATUS_APPROVED => 'Approved',
            default => ucfirst($status),
        };
    }

    /**
     * The status label as the given viewer should see it.
     *
     * A forwarded submission reads differently depending on who is looking:
     * the superadmin it now sits with sees it as their waiting queue entry
     * ("Pending Review"), while everyone else — the original reviewer (the
     * record stays visible in their list) and the staff member — sees where
     * it went ("Forwarded to Superadmin SY").
     */
    public function statusLabelFor(?User $viewer = null): string
    {
        if ($this->status === self::STATUS_FORWARDED) {
            if ($viewer !== null && $this->isAssignedTo($viewer)) {
                return self::statusLabel(self::STATUS_PENDING);
            }

            $recipient = $this->assignedTo?->username;

            return $recipient !== null && $recipient !== ''
                ? 'Forwarded to Superadmin '.$recipient
                : self::statusLabel(self::STATUS_FORWARDED);
        }

        return self::statusLabel((string) $this->status);
    }

    /**
     * The badge class matching the workflow status (tables on both sides).
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED, self::STATUS_FOR_COMPLIANCE => 'badge--ok',
            self::STATUS_RETURNED => 'badge--warn',
            self::STATUS_RESUBMITTED => 'badge--info',
            default => 'badge-muted',
        };
    }

    /**
     * The full review trail, oldest first (the review modal's history
     * table: Reviewer / Action / Remarks / When / From → To).
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(OpcrfReview::class, 'opcrf_submission_id')->oldest('id');
    }

    /**
     * The submission's archived workbook versions, oldest (Version 1, the
     * original upload) first. Nothing is ever overwritten: each resubmission
     * archives the workbook it replaces and gets its own row.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(OpcrfSubmissionVersion::class, 'opcrf_submission_id')->oldest('version_number');
    }

    /**
     * The personalised template this submission was answered on, when the
     * account had generated one. Null is normal: a submission is judged on
     * what its contents say, so an account that never downloaded a template
     * is not blocked from submitting.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OpcrfTemplate::class, 'opcrf_template_id');
    }

    /**
     * The reference a person quotes when they talk about this form —
     * "OPCRF-2026-00025". Minted once, on creation, and never changed.
     *
     * The number is the submission's own id rather than a running tally, so
     * a deleted submission's reference is never handed to a different
     * person, and two submissions can never collide on one.
     */
    protected static function booted(): void
    {
        // `created`, not `creating`: the row's id is only assigned once the
        // INSERT has run, and the reference is derived from it. Minting it
        // in `creating` would read a null id, hand every submission the same
        // "…-00000", and spin forever against the unique index.
        static::created(function (self $submission): void {
            if ($submission->reference === null || $submission->reference === '') {
                $submission->forceFill([
                    'reference' => static::nextReference((int) $submission->id),
                ])->saveQuietly();
            }
        });

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

    /**
     * The free reference for a submission that now owns $id, e.g.
     * "OPCRF-2026-00025". Ids are unique and increase, so the collision
     * guard below is belt-and-braces for an imported row that already
     * carries somebody else's reference.
     */
    protected static function nextReference(int $id): string
    {
        $year = now()->format('Y');

        do {
            $candidate = 'OPCRF-'.$year.'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
            $id++;
        } while (static::where('reference', $candidate)->exists());

        return $candidate;
    }

    /**
     * Does this submission sit on a template that belongs to the account
     * that filed it? A genuine submission always does; a cross-account one
     * is caught here as a second line of defence behind the content check.
     */
    public function wasAnsweredOnOwnTemplate(): bool
    {
        return $this->template === null
            || $this->template->user_id === $this->user_id;
    }

    /**
     * The newest entry of the review trail — feeds the staff table's
     * remarks cell and the status badges' tooltips.
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(OpcrfReview::class, 'opcrf_submission_id')->latestOfMany();
    }

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

    /**
     * What the upload check found when this was filed, in one sentence, or
     * null when there was nothing to say.
     *
     * Nothing here ever withheld the upload — this is context for the
     * reviewer, not a verdict — so every outcome reads as a note rather
     * than a warning badge. Returns null for a clean match and for rows
     * filed before the check existed, so the reviewer is not told about a
     * question nobody asked.
     */
    public function uploadCheckNote(): ?string
    {
        $fileSays = trim((string) $this->employee_name);
        $accountSays = trim((string) ($this->account_name ?? $this->user?->name));

        return match ($this->upload_check) {
            OpcrfTemplatePersonalizer::MISMATCH => sprintf(
                'The name in the uploaded file (“%s”) differs from the account name (“%s”).',
                $fileSays !== '' ? $fileSays : 'blank',
                $accountSays !== '' ? $accountSays : 'blank'
            ),
            OpcrfTemplatePersonalizer::UNREADABLE
                => 'No name could be read from the uploaded file’s header block.',
            OpcrfTemplatePersonalizer::ACCOUNT_UNNAMED
                => 'The account carried no name at upload time, so the file could not be matched.',
            OpcrfTemplatePersonalizer::STAMP_MISMATCH
                => 'This workbook was downloaded from another user’s account, so its name may not belong to this submission.',
            default => null,
        };
    }

    /**
     * Is this submission's upload check something the reviewer should look
     * at before approving?
     *
     * True only when the file names somebody other than the account — the
     * one outcome that suggests the wrong form was filed. A nameless file
     * or a file from another dashboard is context, not a flag.
     */
    public function hasUploadCheckConcern(): bool
    {
        return $this->upload_check === OpcrfTemplatePersonalizer::MISMATCH;
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
     * The superadmin this submission was originally routed to — the staff
     * member picks the recipient when submitting. This never changes, not
     * even after the submission auto-routes onward: the original reviewer's
     * records keep showing the submission (status "Forwarded to …").
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * The superadmin currently holding the review step. Same row, same ID,
     * same workbook — only the assignment moves when the workflow routes
     * the submission onward (Eve's compliance mark → SY).
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Is this submission currently sitting in the given superadmin's queue
     * — i.e. are they the one who may act on it? The current holder is
     * `assigned_to` (falling back to `reviewer_id` for rows predating the
     * assignment column); unassigned rows (pre-routing legacy, or a deleted
     * recipient's orphans) stay actionable by every superadmin.
     */
    public function isAssignedTo(?User $user): bool
    {
        if ($user === null || ! $user->is_superadmin) {
            return false;
        }

        $current = $this->assigned_to ?? $this->reviewer_id;

        return $current === null || $current === $user->id;
    }

    /**
     * Submissions a superadmin may see: the ones originally routed to them
     * (these stay visible even after an onward auto-route — the original
     * reviewer's records keep the row, status "Forwarded to …"), the ones
     * currently assigned to them, plus any unassigned (sent before routing
     * existed, or whose recipient's account was deleted) so a record is
     * never stranded where nobody can see it.
     */
    public function scopeVisibleTo(Builder $query, User $reviewer): Builder
    {
        return $query->where(function (Builder $routed) use ($reviewer): void {
            $routed->where('reviewer_id', $reviewer->id)
                ->orWhere('assigned_to', $reviewer->id)
                ->orWhere(function (Builder $unassigned): void {
                    $unassigned->whereNull('reviewer_id')->whereNull('assigned_to');
                });
        });
    }

    /**
     * Submissions still awaiting the given superadmin's own action: the ones
     * they can see, held by them (or by nobody), and not yet decided.
     *
     * The current holder of the review step counts a row, not the original
     * reviewer it auto-routed onward from (Eve sees a forwarded submission in
     * her list, but the pending work sits with SY now). A compliance mark
     * that routed the submission onward leaves `approved_at` set, so the
     * `forwarded` status is what keeps it in the next holder's queue.
     *
     * Returned submissions are excluded on purpose: they are back with the
     * staff member, who holds no review step, so the reviewer has nothing to
     * act on until the revision comes back. They would otherwise slip in on
     * `approved_at IS NULL` — the return leaves it null.
     */
    public function scopeAwaitingReviewFrom(Builder $query, User $reviewer): Builder
    {
        return $query
            ->visibleTo($reviewer)
            ->where(function (Builder $held) use ($reviewer): void {
                $held->whereNull('assigned_to')->orWhere('assigned_to', $reviewer->id);
            })
            ->where(function (Builder $undecided): void {
                $undecided->whereNull('approved_at')
                    ->orWhere('status', self::STATUS_FORWARDED);
            })
            ->where('status', '!=', self::STATUS_RETURNED);
    }

    /**
     * Submissions the reviewer signed off on: a final approval OR a
     * compliance mark — both mean the submitted OPCRF was approved.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where(function (Builder $signed) {
            $signed->whereNotNull('approved_at')
                ->orWhereIn('status', [self::STATUS_APPROVED, self::STATUS_FOR_COMPLIANCE]);
        });
    }

    /**
     * Has the superadmin reviewed and approved this submission? True for a
     * final approval OR a compliance mark — both mean the reviewer signed
     * off on the submitted OPCRF.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null
            || in_array($this->status, [self::STATUS_APPROVED, self::STATUS_FOR_COMPLIANCE], true);
    }

    /**
     * Is the submission still moving through the review workflow (i.e. not
     * decided either way yet)?
     */
    public function isPending(): bool
    {
        return ! $this->isApproved() && ! in_array($this->status, [self::STATUS_RETURNED], true);
    }

    /**
     * May this user handle the submission at all — its evidence (MOVs),
     * its workbook, opening it for viewing?
     *
     * The owning staff member always can; a superadmin for the submissions
     * routed to them originally AND the ones currently assigned to them —
     * the original reviewer keeps read access even after an onward
     * auto-route, the assignee to review it.
     */
    public function canBeReviewedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->is_superadmin) {
            return $this->reviewer_id === null
                || $this->reviewer_id === $user->id
                || $this->assigned_to === $user->id;
        }

        return $this->user_id === $user->id;
    }

    /**
     * Can the given user download this submission's workbook?
     *
     * The workbook is always the staff member's original upload — reviews
     * never replace it — so the owner may download it at any time, and a
     * superadmin may fetch the ones routed to them (that is the review).
     */
    public function canBeDownloadedBy(?User $user): bool
    {
        return $this->canBeReviewedBy($user);
    }

    /**
     * Hand the submission to another superadmin — a routing step, NOT a
     * final decision: the review trail records who forwarded it, to whom,
     * and any remarks, and the receiving superadmin finds the submission
     * in their own list. Only the assignment moves (`assigned_to`);
     * `reviewer_id` stays as the original reviewer, whose records keep
     * showing the row. The original uploaded workbook is never touched.
     *
     * @return bool false when the recipient already holds the review step
     */
    public function forwardTo(User $actor, User $recipient, ?string $remarks = null): bool
    {
        $current = $this->assigned_to ?? $this->reviewer_id;

        if (! $recipient->is_superadmin || $recipient->id === $current) {
            return false;
        }

        $from = $current;

        $this->assigned_to = $recipient->id;
        $this->status = self::STATUS_FORWARDED;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $actor->id,
            'action' => OpcrfReview::ACTION_FORWARD,
            'remarks' => $remarks,
            'from_id' => $from,
            'to_id' => $recipient->id,
            'reviewed_at' => now(),
        ]);

        return true;
    }

    /**
     * The reviewer's compliance/approval: the submitted OPCRF is signed off
     * as-is — the original uploaded workbook is never replaced. When
     * $routeTo is given (the workflow's next superadmin), the compliant
     * submission is immediately forwarded onward, and the trail records
     * both steps.
     */
    public function markCompliant(User $reviewer, ?string $remarks = null, ?User $routeTo = null): void
    {
        $current = $this->assigned_to ?? $this->reviewer_id;

        $this->approved_at = now();
        $this->approved_by = $reviewer->id;
        $this->status = self::STATUS_FOR_COMPLIANCE;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $reviewer->id,
            'action' => OpcrfReview::ACTION_COMPLIANCE,
            'remarks' => $remarks,
            'from_id' => $this->reviewer_id === $reviewer->id ? null : $this->reviewer_id,
            'to_id' => $routeTo?->id,
            'reviewed_at' => now(),
        ]);

        // The workflow's next hop (SY by config): the SAME submission row
        // auto-routes onward — one ID, one workbook, one history. Only the
        // assignment and status move; Eve's original reviewer_id stays, so
        // her records keep showing it ("Forwarded to Superadmin SY").
        if ($routeTo !== null && $routeTo->id !== $current) {
            $this->forwardTo($reviewer, $routeTo, $remarks);
        }
    }

    /**
     * Send the submission back to the staff member for revision: the status
     * becomes 'returned' (the staff member's table shows it, with an Action
     * Required banner), the review trail records the reviewer, the remarks,
     * and the status transition (Previous → New), and the staff member gets
     * an "OPCRF Returned" notification. The workbook stays exactly as
     * uploaded — resubmission archives it alongside the revision.
     */
    public function returnForRevision(User $reviewer, ?string $remarks = null): void
    {
        $previousStatus = (string) $this->status;

        $this->status = self::STATUS_RETURNED;
        // Back with the staff member: nobody holds the review step while
        // they revise (resubmission reassigns it to the returning reviewer).
        $this->assigned_to = null;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $reviewer->id,
            'action' => OpcrfReview::ACTION_RETURN,
            'remarks' => $remarks,
            // The reviewer is the sender: the history table shows the
            // return as "reviewer → staff member".
            'from_id' => $this->reviewer_id,
            'previous_status' => $previousStatus,
            'new_status' => self::STATUS_RETURNED,
            'reviewed_at' => now(),
        ]);

        // The staff member finds out immediately — not just the next time
        // they happen to open the page.
        $this->user?->notify(new OpcrfReturned($this, $reviewer, $remarks));
    }

    /**
     * The staff member's revised submission: archives the workbook the
     * revision replaces (Version 1 = original, Version 2 = first revision,
     * …), stores the new file as the current one, sets the status to
     * 'resubmitted', and routes the submission back to the superadmin who
     * returned it. The full review history stays attached to the same
     * submission row — one trail per OPCRF, revisions included.
     */
    public function resubmit(?string $newFilePath = null, ?string $newOriginalName = null): void
    {
        // Preserve the replaced workbook as the previous version.
        $this->archiveCurrentFileAsVersion();

        if ($newFilePath !== null) {
            $this->file_path = $newFilePath;
            $this->file_original_name = $newOriginalName;
            $this->file_updated_at = now();
        }

        $this->status = self::STATUS_RESUBMITTED;
        // Route back to the superadmin who returned it — via the current
        // assignment; reviewer_id keeps pointing at the original reviewer.
        $this->assigned_to = $this->returningReviewer()?->id ?? ($this->assigned_to ?? $this->reviewer_id);
        $this->save();

        $this->reviews()->create([
            // The staff member performed the resubmission — the trail's
            // reviewer_id column carries the actor for every action type.
            'reviewer_id' => $this->user_id,
            'action' => OpcrfReview::ACTION_RESUBMIT,
            'remarks' => null,
            'previous_status' => self::STATUS_RETURNED,
            'new_status' => self::STATUS_RESUBMITTED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * The superadmin whose return this submission is waiting on — the last
     * reviewer who ran the return-for-revision action. Null when the trail
     * has no return (never returned).
     */
    public function returningReviewer(): ?User
    {
        return $this->reviews()
            ->where('action', OpcrfReview::ACTION_RETURN)
            ->orderByDesc('id')
            ->first()
            ?->reviewer;
    }

    /**
     * Archive the current workbook (if any) as the next version row, so a
     * resubmission never overwrites the previous submitted copy.
     */
    public function archiveCurrentFileAsVersion(): void
    {
        if ($this->file_path === null) {
            return;
        }

        $this->versions()->create([
            'version_number' => $this->nextVersionNumber(),
            'stored_path' => $this->file_path,
            'original_name' => $this->file_original_name,
            'submitted_at' => $this->file_updated_at ?? $this->submitted_at,
            'status' => $this->getOriginal('status') ?? $this->status,
        ]);
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

    /**
     * "Part 2" for the tables and cards that show which Part a row answers.
     */
    public function partLabel(): string
    {
        return 'Part '.$this->opcrf_part;
    }

    /**
     * The next version row's number: one past the highest archived so far
     * (Version 1 is the original upload, archived when the revision lands).
     */
    private function nextVersionNumber(): int
    {
        return ((int) $this->versions()->max('version_number')) + 1;
    }
}
