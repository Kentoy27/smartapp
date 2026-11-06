<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * An Annual Implementation Plan (AIP) for one school and one year — the
 * school's operational breakdown of its School Improvement Plan.
 *
 * A plan is attached to a SCHOOL, while School Head access is limited to the
 * account that created it (see managedBy()). The contents
 * are two levels — School Improvement Projects, each with its activities —
 * matching the official form (Annex 10, DepEd Order No. 44, s. 2015).
 *
 * The review workflow starts with the assigned Viewer, then moves through
 * the configured superadmin chain:
 *
 *   draft → (submit) → pending → (approve) → forwarded → (final approve) → approved
 *                             ↘ (return) → returned → (resubmit) → resubmitted
 *
 * Every submission archives an immutable snapshot of the plan as a version
 * (Version 1 = the first submission), so a revision never overwrites what was
 * previously submitted and reviewed. `version` is a content counter, bumped
 * whenever the contents are written; the review trail records status
 * transitions.
 */
class AipPlan extends Model
{
    /** Not yet submitted — the owner may edit it freely. */
    public const STATUS_DRAFT = 'draft';

    /** Submitted and waiting for the reviewer. */
    public const STATUS_PENDING = 'pending';

    /** Returned to the owner for revision. */
    public const STATUS_RETURNED = 'returned';

    /** Revised by the owner and waiting for review again. */
    public const STATUS_RESUBMITTED = 'resubmitted';

    /** Approved by one reviewer and routed to the next reviewer. */
    public const STATUS_FORWARDED = 'forwarded';

    /** Approved by the reviewer — the official AIP. */
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'school_id',
        'year',
        'title',
        'form_data',
        'source_file_path',
        'original_file_name',
        'status',
        'version',
        'wfp_plan_id',
        'created_by',
        'updated_by',
        'submitted_at',
        'reviewer_id',
        'assigned_to',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'review_remarks',
    ];

    protected $casts = [
        'year' => 'integer',
        'form_data' => 'array',
        'version' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------ relations */

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The school's Work and Financial Plan for the same year, where one
     * exists — the AIP is financed by the WFP, so the two are connected.
     */
    public function wfpPlan(): BelongsTo
    {
        return $this->belongsTo(WfpPlan::class, 'wfp_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The first reviewer the plan was routed to. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * The superadmin currently holding the review step. Same row, same
     * contents — only the assignment moves when the plan is routed onward.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** School Improvement Projects, in the order the owner arranged them. */
    public function projects(): HasMany
    {
        return $this->hasMany(AipProject::class, 'aip_plan_id')->orderBy('position')->orderBy('id');
    }

    /** Every activity of the plan, in order (used for totals and export). */
    public function activities(): HasMany
    {
        return $this->hasMany(AipActivity::class, 'aip_plan_id')->orderBy('position')->orderBy('id');
    }

    /** The review trail, oldest first (Reviewer / Action / Remarks / When). */
    public function reviews(): HasMany
    {
        return $this->hasMany(AipReview::class, 'aip_plan_id')->oldest('id');
    }

    /** The archived submission versions, oldest (Version 1) first. */
    public function versions(): HasMany
    {
        return $this->hasMany(AipPlanVersion::class, 'aip_plan_id')->oldest('version_number');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(AipReview::class, 'aip_plan_id')->latestOfMany();
    }

    /**
     * The School Head signature for the latest submitted version, if one is
     * available on the account that submitted it.
     *
     * @return array{url: string, name: string, position: string|null, signed_at: string}|null
     */
    public function submitterSignature(): ?array
    {
        if ($this->submitted_at === null) {
            return null;
        }

        $submission = $this->reviews()
            ->whereIn('action', [AipReview::ACTION_SUBMIT, AipReview::ACTION_RESUBMIT])
            ->with('reviewer')
            ->reorder('id', 'desc')
            ->first();
        $submitter = $submission?->reviewer;

        if ($submitter === null || ! $submitter->hasSignature()
            || ! Storage::disk('local')->exists((string) $submitter->signature_path)) {
            return null;
        }

        return [
            'url' => route('aip.signature', ['plan' => $this, 'signer' => $submitter]),
            'name' => (string) ($submitter->name ?: $submitter->username),
            'position' => $submitter->position,
            'signed_at' => $submission->reviewed_at?->format('M j, Y g:i A') ?? '',
        ];
    }

    /**
     * Signatures from the configured AIP reviewers, keyed by their form slot.
     * A signature is shown only after that reviewer has approved this plan.
     *
     * @return array<string, array{url: string, name: string, signed_at: string}>
     */
    public function reviewerSignatures(): array
    {
        if ($this->submitted_at === null) {
            return [];
        }

        $submission = $this->reviews()
            ->whereIn('action', [AipReview::ACTION_SUBMIT, AipReview::ACTION_RESUBMIT])
            ->reorder('id', 'desc')
            ->first();

        if ($submission === null) {
            return [];
        }

        $slots = ['reviewed_by', 'recommending_approval', 'approved_by'];
        $reviewers = static::configuredReviewers();
        $signatures = [];

        foreach ($slots as $index => $slot) {
            $reviewer = $reviewers->get($index);

            if ($reviewer === null || ! $reviewer->hasSignature()
                || ! Storage::disk('local')->exists((string) $reviewer->signature_path)) {
                continue;
            }

            $approval = $this->reviews()
                ->where('id', '>', $submission->id)
                ->where('reviewer_id', $reviewer->id)
                ->where('action', AipReview::ACTION_COMPLIANCE)
                ->reorder('id', 'desc')
                ->first();

            if ($approval === null) {
                continue;
            }

            $signatures[$slot] = [
                'url' => route('aip.signature', ['plan' => $this, 'signer' => $reviewer]),
                'name' => (string) ($reviewer->name ?: $reviewer->username),
                'signed_at' => $approval->reviewed_at?->format('M j, Y g:i A') ?? '',
            ];
        }

        return $signatures;
    }

    /* --------------------------------------------------------- presentation */

    /** "CY 2026" — the planning year, as the form reads it. */
    public function fiscalYearLabel(): string
    {
        return 'CY '.$this->year;
    }

    /** What the plan is called in lists: its title, or a default by year. */
    public function label(): string
    {
        $title = trim((string) $this->title);

        return $title !== '' ? $title : 'Annual Implementation Plan '.$this->fiscalYearLabel();
    }

    /** The file's base name (used by the Excel export and the MOV attachment). */
    public function exportBaseName(): string
    {
        $school = trim((string) ($this->school?->name ?? ''));

        return sprintf(
            'AIP %s%s',
            $this->year,
            $school !== '' ? ' - '.$school : ''
        );
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING => 'Pending Review',
            self::STATUS_RETURNED => 'Returned for Revision',
            self::STATUS_RESUBMITTED => 'Resubmitted for Review',
            self::STATUS_FORWARDED => 'Forwarded',
            self::STATUS_APPROVED => 'Approved',
            default => ucfirst($status),
        };
    }

    public function statusLabelFor(?User $viewer = null): string
    {
        if ($this->status === self::STATUS_FORWARDED) {
            if ($viewer !== null && (int) $this->assigned_to === (int) $viewer->id) {
                return 'Pending Review';
            }

            return 'Forwarded to '.($this->assignedTo?->name ?? 'the next reviewer');
        }

        return self::statusLabel((string) $this->status);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'badge--ok',
            self::STATUS_RETURNED => 'badge--warn',
            self::STATUS_RESUBMITTED, self::STATUS_PENDING, self::STATUS_FORWARDED => 'badge--info',
            default => 'badge-muted',
        };
    }

    /* --------------------------------------------------------------- state */

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED || $this->approved_at !== null;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * May the owner still change the contents? A draft and a returned plan
     * are editable; a plan that is out for review or approved is frozen —
     * submitting a change to it is a revision, which archives a version.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_RETURNED], true);
    }

    /** Is the plan waiting on the reviewer (so they have work to do)? */
    public function isAwaitingReview(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RESUBMITTED, self::STATUS_FORWARDED], true);
    }

    /* ------------------------------------------------------------ authority */

    /** Only the School Head who created this plan may access it. */
    public function managedBy(?User $user): bool
    {
        return $user !== null
            && $user->canAccessAip()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id
            && (int) $this->created_by === (int) $user->id;
    }

    /**
     * Is this plan currently sitting in the given reviewer's queue — i.e.
     * may they act on it? An assigned plan is actionable only by its current
     * holder; an unassigned legacy plan remains recoverable by any reviewer.
     */
    public function isAssignedTo(?User $user): bool
    {
        if ($user === null || ! $user->canReviewAip()) {
            return false;
        }

        $current = $this->assigned_to ?? $this->reviewer_id;

        return $current === null || (int) $current === (int) $user->id;
    }

    /** May this user read/manage the plan (its owner or its reviewer)? */
    public function canBeAccessedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->canReviewAip()) {
            return $this->isAssignedTo($user);
        }

        return $this->managedBy($user);
    }

    public function canBeReviewedBy(?User $user): bool
    {
        return $user !== null && $user->canReviewAip() && $this->isAssignedTo($user);
    }

    /** Admins may manage any visible plan; a Viewer may delete only their assigned submission. */
    public function canBeDeletedBy(?User $user): bool
    {
        return $user !== null && (
            $user->hasAdminAccess()
            || ($user->role === 'viewer'
                && $this->assigned_to !== null
                && (int) $this->assigned_to === (int) $user->id
                && $this->canBeReviewedBy($user))
        );
    }

    /**
     * May this user open the plan at all — its contents, its PDF and its
     * Excel export?
     *
     * The owning School Head always may. A reviewer may once the plan has
     * been submitted (a draft is the school's own working copy).
     */
    public function canBeViewedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->managedBy($user)) {
            return true;
        }

        if (! $user->canReviewAip() || $this->status === self::STATUS_DRAFT) {
            return false;
        }

        return $this->isAssignedTo($user);
    }

    /* -------------------------------------------------------- scope helpers */

    /**
     * Plans a reviewer may see: ones routed to them, currently assigned to
     * them, ones they have already reviewed, or unassigned legacy records.
     * Prior reviewers retain read access after forwarding, but can no longer
     * act on the plan.
     */
    public function scopeVisibleTo(Builder $query, User $reviewer): Builder
    {
        if ($reviewer->canReviewAip() && ! $reviewer->is_superadmin) {
            return $query;
        }

        return $query->where(function (Builder $routed) use ($reviewer): void {
            $routed->where('reviewer_id', $reviewer->id)
                ->orWhere('assigned_to', $reviewer->id)
                ->orWhereHas('reviews', fn (Builder $reviews): Builder => $reviews
                    ->where('reviewer_id', $reviewer->id))
                ->orWhere(function (Builder $unassigned): void {
                    $unassigned->whereNull('reviewer_id')->whereNull('assigned_to');
                });
        });
    }

    /**
     * Plans still awaiting the given reviewer's own action — the ones they
     * can see, held by them (or nobody), and not yet decided.
     */
    public function scopeAwaitingReviewFrom(Builder $query, User $reviewer): Builder
    {
        $query = $query->visibleTo($reviewer);

        if ($reviewer->role === 'viewer') {
            $query->where(function (Builder $held) use ($reviewer): void {
                $held->where('assigned_to', $reviewer->id)
                    ->orWhere(function (Builder $legacy) use ($reviewer): void {
                        $legacy->whereNull('assigned_to')->where('reviewer_id', $reviewer->id);
                    });
            });
        } elseif (! ($reviewer->canReviewAip() && ! $reviewer->is_superadmin)) {
            $query->where(function (Builder $held) use ($reviewer): void {
                $held->whereNull('assigned_to')->orWhere('assigned_to', $reviewer->id);
            });
        }

        return $query
            ->whereNull('approved_at')
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RESUBMITTED, self::STATUS_FORWARDED]);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where(function (Builder $signed): void {
            $signed->whereNotNull('approved_at')
                ->orWhere('status', self::STATUS_APPROVED);
        });
    }

    /* -------------------------------------------------------------- totals */

    /** The annual budget: the sum of every activity's budget per activity. */
    public function annualBudgetTotal(): float
    {
        if ($this->form_data !== null) {
            return round(array_sum(array_map(
                static fn (array $row): float => self::numericBudget($row['budget'] ?? ''),
                $this->form_data['rows'] ?? [],
            )), 2);
        }

        return round((float) $this->activities()->sum('budget'), 2);
    }

    /** Number of distinct pillar/objective groups shown in the annual form. */
    public function projectCount(): int
    {
        if ($this->form_data === null) {
            return $this->projects()->count();
        }

        return collect($this->form_data['rows'] ?? [])
            ->map(fn (array $row): string => trim((string) ($row['pillar'] ?? '')).'|'.trim((string) ($row['strategic_objective'] ?? '')))
            ->filter(fn (string $key): bool => $key !== '|')
            ->unique()
            ->count();
    }

    /** Number of implementation rows containing a PPA. */
    public function activityCount(): int
    {
        if ($this->form_data === null) {
            return $this->activities()->count();
        }

        return collect($this->form_data['rows'] ?? [])
            ->filter(fn (array $row): bool => trim((string) ($row['ppa'] ?? '')) !== '')
            ->count();
    }

    private static function numericBudget(mixed $budget): float
    {
        $value = preg_replace('/[,\s₱]/u', '', (string) $budget) ?? '';

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** Budgets grouped by their source, for the export's summary block. */
    public function budgetBySource(): array
    {
        return $this->activities()
            ->selectRaw('COALESCE(budget_source, ?) as source, SUM(COALESCE(budget, 0)) as total', ['Unspecified'])
            ->groupBy('source')
            ->orderByDesc('total')
            ->pluck('total', 'source')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();
    }

    /**
     * Advance the content version. Called whenever the contents are written,
     * so the editor's optimistic lock can tell that somebody else has saved
     * the plan since it was loaded.
     */
    public function bumpVersion(): int
    {
        // Read the current version from the database rather than trusting the
        // in-memory value, which may be stale (the plan may have been written
        // through another instance since this one was loaded).
        $current = (int) static::query()->whereKey($this->getKey())->value('version');

        $this->version = $current + 1;
        $this->save();

        return $this->version;
    }

    /* ------------------------------------------------------------ workflow */

    /**
     * The owner submits the plan. Archives the current contents as a version,
     * routes it to the first configured reviewer, and records the submission.
     */
    public function submit(User $owner, ?string $remarks = null): void
    {
        $previous = (string) $this->status;

        $this->archiveVersion(self::STATUS_PENDING, $owner);

        $this->status = self::STATUS_PENDING;
        $this->submitted_at = now();
        $this->reviewer_id = self::configuredReviewerId() ?? $this->reviewer_id;
        $this->assigned_to = $this->reviewer_id;
        $this->review_remarks = null;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $owner->id,
            'action' => AipReview::ACTION_SUBMIT,
            'remarks' => $remarks,
            'previous_status' => $previous,
            'new_status' => self::STATUS_PENDING,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * The owner revises and resubmits: archives the revision as the next
     * version and sends it back to the reviewer for another look.
     */
    public function resubmit(User $owner, ?string $remarks = null): void
    {
        $previous = (string) $this->status;

        $this->archiveVersion(self::STATUS_RESUBMITTED, $owner);

        $this->status = self::STATUS_RESUBMITTED;
        $this->submitted_at = now();
        // Route back to the reviewer who returned it, else the original one.
        $this->assigned_to = $this->returningReviewer()?->id ?? ($this->assigned_to ?? $this->reviewer_id);
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $owner->id,
            'action' => AipReview::ACTION_RESUBMIT,
            'remarks' => $remarks,
            'previous_status' => $previous,
            'new_status' => self::STATUS_RESUBMITTED,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * The reviewer approves the submitted plan — this is the only path that
     * sets 'approved'; nothing marks a plan approved implicitly.
     */
    public function markApproved(User $reviewer, ?string $remarks = null): ?User
    {
        $previous = (string) $this->status;
        $nextReviewer = self::nextConfiguredReviewer($reviewer);
        $finalApproval = $nextReviewer === null;

        $this->status = $finalApproval ? self::STATUS_APPROVED : self::STATUS_FORWARDED;
        $this->approved_at = $finalApproval ? now() : null;
        $this->approved_by = $finalApproval ? $reviewer->id : null;
        if ($nextReviewer !== null) {
            $this->assigned_to = $nextReviewer->id;
        }
        $this->reviewed_at = now();
        $this->review_remarks = $remarks;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $reviewer->id,
            'action' => AipReview::ACTION_COMPLIANCE,
            'remarks' => $remarks,
            'previous_status' => $previous,
            'new_status' => self::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        return $nextReviewer;
    }

    /**
     * The reviewer returns the plan for revision, with the remarks that say
     * what to change. The plan goes back to the owner (nobody holds the
     * review step while they revise).
     */
    public function returnForRevision(User $reviewer, ?string $remarks = null): void
    {
        $previous = (string) $this->status;

        $this->status = self::STATUS_RETURNED;
        $this->assigned_to = null;
        $this->reviewed_at = now();
        $this->review_remarks = $remarks;
        $this->save();

        $this->reviews()->create([
            'reviewer_id' => $reviewer->id,
            'action' => AipReview::ACTION_RETURN,
            'remarks' => $remarks,
            'previous_status' => $previous,
            'new_status' => self::STATUS_RETURNED,
            'reviewed_at' => now(),
        ]);
    }

    /** The reviewer whose return the plan is waiting on, if any. */
    public function returningReviewer(): ?User
    {
        return $this->reviews()
            ->where('action', AipReview::ACTION_RETURN)
            ->orderByDesc('id')
            ->first()
            ?->reviewer;
    }

    /**
     * The first configured AIP reviewer (by username). The chain allows a
     * Viewer at its first step and administrative accounts at later steps.
     */
    public static function configuredReviewerId(): ?int
    {
        return static::configuredReviewers()->first()?->id;
    }

    /** The next configured reviewer after the person taking this step. */
    public static function nextConfiguredReviewer(User $reviewer): ?User
    {
        $reviewers = static::configuredReviewers();
        $currentIndex = $reviewers->search(
            static fn (User $candidate): bool => (int) $candidate->id === (int) $reviewer->id,
        );

        return $currentIndex === false ? null : $reviewers->get($currentIndex + 1);
    }

    /**
     * Resolve configured usernames fresh so user IDs may differ by environment.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private static function configuredReviewers(): \Illuminate\Support\Collection
    {
        $usernames = array_values(array_unique(array_filter(array_map(
            static fn (mixed $username): string => trim((string) $username),
            [
                config('aip.review_route'),
                config('aip.next_reviewer'),
                config('aip.final_reviewer'),
            ],
        ), static fn (string $username): bool => $username !== '')));

        $reviewers = collect();

        foreach ($usernames as $index => $username) {
            $reviewer = User::query()
                ->where('username', $username)
                ->where(function (Builder $role) use ($index): void {
                    if ($index === 0) {
                        $role->where('role', 'viewer')
                            ->orWhere('is_superadmin', true)
                            ->orWhere('role', 'administrator');
                    } else {
                        $role->where('is_superadmin', true)
                            ->orWhere('role', 'administrator');
                    }
                })
                ->first();

            if ($reviewer !== null) {
                $reviewers->push($reviewer);
            }
        }

        return $reviewers;
    }

    /* ------------------------------------------------------------ versions */

    /**
     * The plan's contents right now, as a plain array — what a version
     * snapshot stores and what the preview/print render.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'title' => $this->title,
            'year' => (int) $this->year,
            'school' => $this->school?->name,
            'form_data' => $this->form_data,
            'projects' => $this->projects()->with('activities')->get()
                ->map(fn (AipProject $project): array => [
                    'title' => $project->title,
                    'objective' => $project->objective,
                    'output_for_year' => $project->output_for_year,
                    'activities' => $project->activities->map(fn (AipActivity $activity): array => [
                        'activity' => $activity->activity,
                        'person_responsible' => $activity->person_responsible,
                        'schedule' => $activity->schedule,
                        'venue' => $activity->venue,
                        'budget' => $activity->budget !== null ? (float) $activity->budget : null,
                        'budget_source' => $activity->budget_source,
                    ])->all(),
                ])->all(),
            'budget_total' => $this->annualBudgetTotal(),
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Archive the current contents as the next immutable version. Never
     * overwrites a previous version.
     */
    public function archiveVersion(string $status, ?User $submittedBy = null): AipPlanVersion
    {
        return $this->versions()->create([
            'version_number' => ((int) $this->versions()->max('version_number')) + 1,
            'status' => $status,
            'snapshot' => $this->snapshot(),
            'submitted_by' => $submittedBy?->id,
            'submitted_at' => now(),
        ]);
    }

    /* ---------------------------------------------------------- linkage */

    /**
     * The MOV requirement the approved AIP is evidence for — located by a
     * keyword in the requirement's title rather than a hardcoded id, so a
     * checklist sync that renumbers MOVs cannot detach the feature.
     */
    public static function movRequirement(): ?MovRequirement
    {
        $keyword = trim((string) config('aip.mov_keyword', 'Annual Implementation Plan'));

        if ($keyword === '') {
            return null;
        }

        return MovRequirement::query()
            ->active()
            ->where('title', 'like', '%'.$keyword.'%')
            ->orderBy('mov_number')
            ->first();
    }
}
