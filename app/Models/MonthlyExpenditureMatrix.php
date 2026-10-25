<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Monthly Expenditure Matrix — one school's expenditure lines for one
 * calendar month. The MEM is the source of truth a WFP is generated from: the
 * SH enters the objectives, activities, quantities and costs once, here, and
 * the WFP is mapped from them.
 *
 * The matrix is owned by a school (not a user), so a change of school head does
 * not strand it. Authorization is by school: `managedBy()` checks the SH's
 * `canAccessWfp()` AND that the account's school is this matrix's school.
 *
 * `version` is bumped every time the lines are written; a generated WFP records
 * the version each of its rows came from, which is how "out of date" is
 * derived (see WfpPlan::isOutOfDate()).
 */
class MonthlyExpenditureMatrix extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_FINALIZED = 'finalized';

    public const SECTION_OPERATIONS = 'operations';

    public const SECTION_PROJECT = 'project';

    protected $fillable = [
        'school_id',
        'year',
        'month',
        'status',
        'version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'version' => 'integer',
    ];

    /** The month's abbreviation, 1-based, e.g. "Jan". */
    public const MONTH_ABBREVIATIONS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MonthlyExpenditureItem::class, 'matrix_id')->orderBy('position')->orderBy('id');
    }

    /** The planning year, as the form reads it ("CY 2026"). */
    public function fiscalYearLabel(): string
    {
        return 'CY '.$this->year;
    }

    /** "January 2026" — the month a matrix covers, written out. */
    public function periodLabel(): string
    {
        $name = date('F', mktime(0, 0, 0, max(1, min(12, (int) $this->month)), 1, 2000));

        return $name.' '.$this->year;
    }

    public function monthLabel(): string
    {
        return self::MONTH_ABBREVIATIONS[(int) $this->month] ?? (string) $this->month;
    }

    /**
     * Advance the version. Called whenever the lines are written, so a WFP
     * mapped from an older version reads as out of date.
     */
    public function bumpVersion(): int
    {
        // Read the current version from the database rather than trusting the
        // in-memory value, which may be stale (the matrix may have been written
        // through another instance since this one was loaded). This keeps a
        // concurrent write from being clobbered by a stale increment.
        $current = (int) static::query()->whereKey($this->getKey())->value('version');

        $this->version = $current + 1;
        $this->save();

        return $this->version;
    }

    /** Only the SH of this school may manage it. */
    public function managedBy(?User $user): bool
    {
        return $user !== null
            && $user->canManageWfp()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id;
    }

    /**
     * May this user open the matrix at all? Currently the owning School Head
     * — the same account set as {@see managedBy()}, kept as its own gate so
     * the page routes and every write can diverge without touching each
     * other (the Bookkeeper checks lines on the division-wide Monthly
     * Expenditure page instead, never here).
     */
    public function canBeViewedBy(?User $user): bool
    {
        return $user !== null
            && $user->canAccessWfp()
            && $this->school_id !== null
            && (int) $this->school_id === (int) $user->school_id;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
