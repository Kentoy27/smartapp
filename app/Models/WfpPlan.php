<?php

namespace App\Models;

use App\Models\Concerns\HasSchoolDivision;
use App\Support\WfpAllotment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A Work and Financial Plan for one school and year — generated from that
 * school's Monthly Expenditure Matrices and reconciled in place.
 *
 * Out-of-date is DERIVED, never stored: the plan is out of date when any of its
 * lines was mapped from a MEM version that is no longer the matrix's current
 * version (see `isOutOfDate()` / `staleSources()`).
 */
class WfpPlan extends Model
{
    use HasFactory, HasSchoolDivision;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_GENERATED = 'generated';

    /** Submitted by the SH and waiting for the Superadmin's final decision. */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    /** Returned by the Superadmin; the SH may revise and resubmit. */
    public const STATUS_RETURNED = 'returned';

    /** Approved by the Superadmin — the official, read-only WFP. */
    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'school_id',
        'school_division',
        'year',
        'template_version',
        'status',
        'generated_by',
        'submitted_by',
        'approved_by',
        'generated_at',
        'submitted_at',
        'approved_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'generated_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Every final decision, oldest first — the audit trail shown beside the
     * plan and used to find the current sign-off.
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(WfpApproval::class, 'wfp_plan_id')->orderBy('id');
    }

    /** The approval that made this plan official, if one was recorded. */
    public function finalApproval(): HasOne
    {
        return $this->hasOne(WfpApproval::class, 'wfp_plan_id')
            ->where('decision', WfpApproval::DECISION_APPROVED)
            ->latestOfMany();
    }

    /** The most recent return-for-revision decision, for the SH's banner. */
    public function latestReturn(): HasOne
    {
        return $this->hasOne(WfpApproval::class, 'wfp_plan_id')
            ->where('decision', WfpApproval::DECISION_RETURNED)
            ->latestOfMany();
    }

    public function items(): HasMany
    {
        return $this->hasMany(WfpItem::class, 'wfp_plan_id')->orderBy('position')->orderBy('id');
    }

    /**
     * The plan's lines grouped by `group_key`, preserving position order, so
     * every read-only rendering reads as one WFP row per grouping.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groupedItems(): array
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('sourceMatrix')->get();

        $groups = [];

        foreach ($items as $item) {
            /** @var WfpItem $item */
            $key = (string) $item->group_key;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'section' => $item->section,
                    'objective' => $item->objective,
                    'program_activity' => $item->program_activity,
                    'account_title' => $item->account_title,
                    'account_code' => $item->account_code,
                    'items' => [],
                ];
            }

            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    public function fiscalYearLabel(): string
    {
        return 'CY '.$this->year;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isApproved(): bool
    {
        return $this->isFinalized();
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * Whether the plan's contents may still change. A plan waiting for the
     * Superadmin — and an approved one — is read-only. A returned plan is
     * editable again, but cannot be resubmitted until Budget re-certifies.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [
            self::STATUS_DRAFT,
            self::STATUS_GENERATED,
            self::STATUS_RETURNED,
        ], true);
    }

    /** The readable status shown on the plan and in the approval queue. */
    public static function labelForStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_GENERATED => 'Generated',
            self::STATUS_PENDING_APPROVAL => 'Pending Approval',
            self::STATUS_RETURNED => 'Returned for Revision',
            self::STATUS_FINALIZED => 'Approved',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function statusLabel(): string
    {
        return self::labelForStatus((string) $this->status);
    }

    /** The statuses the Superadmin's queue records: decisions and pending work. */
    public function scopeInApprovalQueue(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_FINALIZED,
            self::STATUS_RETURNED,
        ]);
    }

    /** Only the SH of this school — in this division — may manage it. */
    public function managedBy(?User $user): bool
    {
        return $user !== null
            && $user->canManageWfp()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id
            && $user->canAccessDivision($this->schoolDivision());
    }

    /**
     * The owning School Head, Superadmin, and in-scope Budget/Bookkeeper
     * readers may open it. The write gates stay separate, so every reader but
     * the SH is read-only.
     */
    public function canBeViewedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->canApproveWfp()) {
            return true;
        }

        if ($this->school_id === null || ! $user->canAccessDivision($this->schoolDivision())) {
            return false;
        }

        // Budget and Bookkeeper accounts read the plans their checking role
        // covers; a Bookkeeper's assignment is re-read here, never trusted
        // from a request.
        if ($user->canCheckLineItems()) {
            return $user->canAccessSchool((int) $this->school_id);
        }

        return $user->canAccessWfp()
            && (int) $this->school_id === (int) $user->school_id;
    }

    /* ------------------------------------------------- Budget readiness */

    /**
     * The Budget office's certification of this plan's allotment, or null
     * when the allotment has not been certified.
     *
     * @return array{name: string, position: string, certified_at: \Carbon\CarbonInterface}|null
     */
    public function budgetCertification(): ?array
    {
        return WfpAllotment::certification(
            $this->school_id !== null ? (int) $this->school_id : null,
            (int) $this->year,
            $this->schoolDivision(),
        );
    }

    /**
     * The Bookkeeper's check of the plan's account titles and codes, or null
     * when the report has not been checked.
     *
     * @return array{name: string, position: string, checked_at: \Carbon\CarbonInterface}|null
     */
    public function budgetChecking(): ?array
    {
        return WfpAllotment::checking(
            $this->school_id !== null ? (int) $this->school_id : null,
            (int) $this->year,
            $this->schoolDivision(),
        );
    }

    /** Budget's two sign-offs are both required before final approval. */
    public function hasBudgetReview(): bool
    {
        return $this->budgetCertification() !== null
            && $this->budgetChecking() !== null;
    }

    /* -------------------------------------------------------- transitions */

    /**
     * The SH files the plan for the Superadmin's final decision. Budget must
     * already have certified the allotment and checked the account titles and
     * codes, and the plan must still match the MEM it was generated from.
     */
    public function submitForApproval(User $owner): void
    {
        if (! $this->managedBy($owner)) {
            throw new RuntimeException('Only the owning School Head may submit this WFP.');
        }

        if (! $this->isEditable()) {
            throw new RuntimeException('This WFP is no longer editable.');
        }

        if (! $this->items()->exists()) {
            throw new RuntimeException('Add at least one line before submitting the WFP.');
        }

        if ($this->isOutOfDate()) {
            throw new RuntimeException('This WFP is out of date. Regenerate it from the MEM before submitting.');
        }

        if (! $this->hasBudgetReview()) {
            throw new RuntimeException('Budget must certify the allotment and check the account titles and codes before this WFP can be submitted.');
        }

        $this->forceFill([
            'status' => self::STATUS_PENDING_APPROVAL,
            'submitted_by' => $owner->id,
            'submitted_at' => now(),
            // A pending plan never carries a prior approval.
            'approved_by' => null,
            'approved_at' => null,
        ])->save();
    }

    /**
     * Record the Superadmin's final approval. The status check, Budget
     * re-check and history row happen under one lock so a double click or a
     * second reviewer cannot approve the same submission twice.
     */
    public function markApproved(User $approver, ?string $remarks = null): void
    {
        if (! $approver->canApproveWfp()) {
            throw new RuntimeException('Only the Superadmin may give final approval to a WFP.');
        }

        $remarks = trim((string) $remarks);

        DB::transaction(function () use ($approver, $remarks): void {
            $plan = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $plan->isPendingApproval()) {
                throw new RuntimeException('This WFP is no longer pending approval.');
            }

            if ($plan->isOutOfDate()) {
                throw new RuntimeException('This WFP is out of date and cannot be approved. Return it for revision instead.');
            }

            if (! $plan->hasBudgetReview()) {
                throw new RuntimeException('Budget review is no longer current. Return this WFP for revision instead.');
            }

            $decidedAt = now();

            $plan->forceFill([
                'status' => self::STATUS_FINALIZED,
                'approved_by' => $approver->id,
                'approved_at' => $decidedAt,
            ])->save();

            $plan->approvals()->create([
                'decision' => WfpApproval::DECISION_APPROVED,
                'decided_by' => $approver->id,
                'remarks' => $remarks !== '' ? $remarks : null,
                'decided_at' => $decidedAt,
            ]);
        });

        $this->refresh();

    }

    /**
     * Return a pending plan for revision. Budget's certification and check
     * are cleared because the lines they signed off on are about to change;
     * the plan cannot be resubmitted until Budget signs off again.
     */
    public function returnForRevision(User $approver, string $remarks): void
    {
        if (! $approver->canApproveWfp()) {
            throw new RuntimeException('Only the Superadmin may return a WFP for revision.');
        }

        $remarks = trim($remarks);

        if ($remarks === '') {
            throw new RuntimeException('Remarks are required when returning a WFP for revision.');
        }

        DB::transaction(function () use ($approver, $remarks): void {
            $plan = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $plan->isPendingApproval()) {
                throw new RuntimeException('This WFP is no longer pending approval.');
            }

            $decidedAt = now();

            $plan->forceFill([
                'status' => self::STATUS_RETURNED,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            $plan->approvals()->create([
                'decision' => WfpApproval::DECISION_RETURNED,
                'decided_by' => $approver->id,
                'remarks' => $remarks,
                'decided_at' => $decidedAt,
            ]);

            // The Budget sign-offs describe the lines as they were. The SH
            // is about to revise them, so both must be given again.
            WfpReportCheck::clearFor(
                $plan->school_id !== null ? (int) $plan->school_id : null,
                (int) $plan->year,
                $plan->schoolDivision(),
            );

            SchoolAllotment::query()
                ->where('school_id', $plan->school_id)
                ->where('school_division', $plan->schoolDivision())
                ->where('year', $plan->year)
                ->update([
                    'certified_by' => null,
                    'certified_at' => null,
                ]);

        });

        $this->refresh();
    }

    /**
     * The recorded approver's name, or null when the plan has no approval
     * record (including a legacy SH-finalized plan) or the approving account
     * was deleted. The exporter prints only this, never a placeholder.
     */
    public function finalApproverName(): ?string
    {
        $name = trim((string) ($this->finalApproval?->decidedBy?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The annual financial target: the sum of every line's financial target,
     * rounded to two decimals (SQLite hands back a float from sum(), so the
     * rounding happens here rather than at the database).
     */
    public function annualFinancialTotal(): float
    {
        return round((float) $this->items()->sum('financial_target'), 2);
    }

    /** How many lines still carry an unreviewed flag. */
    public function flaggedCount(): int
    {
        return $this->items()->where('needs_review', true)->count();
    }

    /**
     * Is the plan out of date? True when any line's recorded source version no
     * longer matches its matrix's current version.
     */
    public function isOutOfDate(): bool
    {
        foreach ($this->items()->with('sourceMatrix')->get() as $item) {
            if ($item->isStale()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The per-matrix breakdown behind the out-of-date banner: each source matrix
     * that has moved on, with how many lines are affected and the versions
     * involved.
     *
     * @return array<int, array{matrix: MonthlyExpenditureMatrix, items: int, was: int, now: int}>
     */
    public function staleSources(): array
    {
        $sources = [];

        foreach ($this->items()->with('sourceMatrix')->get() as $item) {
            $matrix = $item->sourceMatrix;

            if ($matrix === null || ! $item->isStale()) {
                continue;
            }

            $id = (int) $matrix->id;

            if (! isset($sources[$id])) {
                $sources[$id] = [
                    'matrix' => $matrix,
                    'items' => 0,
                    'was' => (int) $item->source_matrix_version,
                    'now' => (int) $matrix->version,
                ];
            }

            $sources[$id]['items']++;
        }

        return array_values($sources);
    }
}
