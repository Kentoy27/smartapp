<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a school allotment's audit trail: the amount that was on the
 * row before and after a change, who made it, and whether it came from the
 * single-row Save or a bulk apply.
 *
 * Append-only: rows are created when an amount is set or cleared and never
 * updated, so `updated_at` is suppressed and only `created_at` is kept. The
 * entry is keyed by school and year rather than by the allotment row's id,
 * because clearing deletes the row and a later re-set creates a new one — the
 * trail must read as one history of the school's year.
 */
class SchoolAllotmentAudit extends Model
{
    public const UPDATED_AT = null;

    /** An amount was set (created or changed). */
    public const ACTION_SET = 'SET';

    /** The allotment was cleared ("not set"). */
    public const ACTION_CLEAR = 'CLEAR';

    /** The change came from the single-row Save. */
    public const SOURCE_SINGLE = 'single';

    /** The change came from a bulk apply. */
    public const SOURCE_BULK = 'bulk_set';

    protected $fillable = [
        'school_id',
        'year',
        'user_id',
        'action',
        'previous_amount',
        'amount',
        'source',
    ];

    protected $casts = [
        'year' => 'integer',
        'previous_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** The school the entry describes (null once the school is deleted). */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** The account that made the change. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append one entry to the trail. `$amount` is the figure the allotment now
     * holds — null when the allotment was cleared — and `$previousAmount` is
     * what it held before, null when there was nothing on record.
     */
    public static function record(
        int $schoolId,
        int $year,
        ?User $user,
        string $action,
        ?float $previousAmount,
        ?float $amount,
        string $source,
    ): self {
        return self::query()->create([
            'school_id' => $schoolId,
            'year' => $year,
            'user_id' => $user?->id,
            'action' => $action,
            'previous_amount' => $previousAmount,
            'amount' => $amount,
            'source' => $source,
        ]);
    }
}
