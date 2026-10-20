<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An Annual Implementation Plan (AIP) for one school and one year — the
 * school's operational breakdown of its School Improvement Plan.
 *
 * A plan is owned by a SCHOOL (not a user), so a change of school head does
 * not strand it; authorization is by school (see managedBy()). The contents
 * are two levels — School Improvement Projects, each with its activities —
 * matching the official form (Annex 10, DepEd Order No. 44, s. 2015).
 *
 * The review workflow mirrors the OPCRF one, deliberately, so the two modules
 * read the same to the superadmin who reviews both:
 *
 *   draft → (submit) → pending → (compliance) → approved
 *                             ↘ (return) → returned → (resubmit) → resubmitted
 *                                                                      ↘ …
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

    /** Approved by the reviewer — the official AIP. */
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'school_id',
        'year',
        'title',
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

    /** The superadmin the plan was routed to (the reviewer of record). */
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
            self::STATUS_APPROVED => 'Approved',
            default => ucfirst($status),
        };
    }

    public function statusLabelFor(?User $viewer = null): string
    {
        return self::statusLabel((string) $this->status);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPROVED => 'badge--ok',
            self::STATUS_RETURNED => 'badge--warn',
            self::STATUS_RESUBMITTED, self::STATUS_PENDING => 'badge--info',
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
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RESUBMITTED], true);
    }

    /* ------------------------------------------------------------ authority */

    /** Only the School Head of this school may author it. */
    public function managedBy(?User $user): bool
    {
        return $user !== null
            && $user->canAccessAip()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id;
    }

    /**
     * Is this plan currently sitting in the given reviewer's queue — i.e.
     * may they act on it? An unassigned plan is actionable by every
     * administrative account.
     */
    public function isAssignedTo(?User $user): bool
    {
        if ($user === null || ! $user->canReviewAip()) {
            return false;
        }

        if (! $user->is_superadmin) {
            return true;
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

    /**
     * May this user open the plan at all — its contents, its PDF and its
     * Excel export?
     *
     * The owning School Head always may. An administrative account may once
     * the plan has been submitted (a draft is the school's own working copy,
     * so it is not shown to reviewers before it is filed).
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
     * Plans a reviewer may see: the ones routed to them, the ones currently
     * assigned to them, plus any unassigned so a record is never stranded.
     */
    public function scopeVisibleTo(Builder $query, User $reviewer): Builder
    {
        if ($reviewer->canReviewAip() && ! $reviewer->is_superadmin) {
            return $query;
        }

        return $query->where(function (Builder $routed) use ($reviewer): void {
            $routed->where('reviewer_id', $reviewer->id)
                ->orWhere('assigned_to', $reviewer->id)
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

        if (! ($reviewer->canReviewAip() && ! $reviewer->is_superadmin)) {
            $query->where(function (Builder $held) use ($reviewer): void {
                $held->whereNull('assigned_to')->orWhere('assigned_to', $reviewer->id);
            });
        }

        return $query
            ->whereNull('approved_at')
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RESUBMITTED]);
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
        return round((float) $this->activities()->sum('budget'), 2);
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

    public function projectCount(): int
    {
        return $this->projects()->count();
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

    public function activityCount(): int
    {
        return $this->activities()->count();
    }

    /* ------------------------------------------------------------ workflow */

    /**
     * The owner submits the plan. Archives the current contents as a version,
     * routes it to the configured reviewer (or leaves it unassigned so every
     * superadmin sees it) and records the submission in the trail.
     */
    public function submit(User $owner, ?string $remarks = null): void
    {
        $previous = (string) $this->status;

        $this->archiveVersion(self::STATUS_PENDING, $owner);

        $this->status = self::STATUS_PENDING;
        $this->submitted_at = now();
        $this->reviewer_id = $this->reviewer_id ?? self::configuredReviewerId();
        $this->assigned_to = $this->assigned_to ?? $this->reviewer_id;
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
    public function markApproved(User $reviewer, ?string $remarks = null): void
    {
        $previous = (string) $this->status;

        $this->status = self::STATUS_APPROVED;
        $this->approved_at = now();
        $this->approved_by = $reviewer->id;
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
     * The configured first reviewer (by username) — the same convention the
     * OPCRF workflow uses. Null when unset or the account is gone, in which
     * case the plan is unassigned and visible to every superadmin.
     */
    public static function configuredReviewerId(): ?int
    {
        $username = trim((string) config('opcrf.review_route', ''));

        if ($username === '') {
            return null;
        }

        return User::query()
            ->where(function (Builder $admin): void {
                $admin->where('is_superadmin', true)->orWhere('role', 'administrator');
            })
            ->where('username', $username)
            ->value('id');
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
