<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the OPCRF schedule audit trail.
 *
 * Every change a superadmin makes to a Part's access window is recorded
 * here: who did it (by id AND by name, copied at the time), which year and
 * Part, what the window was before and after, and which action it was.
 *
 * The name is a copy on purpose. Renaming or deleting a superadmin account
 * must not rewrite what the record says — a deadline is something staff plan
 * around, and "Eve extended Part 2 to March 5" has to stay true even if Eve's
 * account is later renamed or removed.
 *
 * A log entry outlives its schedule: `opcrf_schedule_id` is nullable and not
 * cascaded, so deleting a Part's schedule keeps the record of why it went.
 */
class OpcrfScheduleLog extends Model
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_ENABLED = 'enabled';

    public const ACTION_DISABLED = 'disabled';

    public const ACTION_DELETED = 'deleted';

    public const ACTION_EXTENDED = 'extended';

    /**
     * The audit trail is append-only: a row is written once and never edited,
     * so there is no such thing as when it was last updated. Declaring the
     * updated column as null keeps Eloquent stamping created_at for us while
     * the table itself carries no updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'opcrf_schedule_id',
        'actor_id',
        'actor_name',
        'opcrf_year',
        'part_number',
        'action',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'opcrf_year' => 'integer',
        'part_number' => 'integer',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Record a change to a Part's window.
     *
     * @param  array<string, mixed>|null  $old  the window before the change
     * @param  array<string, mixed>|null  $new  the window after it
     */
    public static function record(
        ?User $actor,
        OpcrfSchedule $schedule,
        string $action,
        ?array $old = null,
        ?array $new = null,
    ): self {
        return self::create([
            'opcrf_schedule_id' => $schedule->id,
            'actor_id' => $actor?->id,
            // Snapshotted, not resolved later: history must not be rewritten
            // when an account changes.
            'actor_name' => (string) ($actor?->name ?? 'System'),
            'opcrf_year' => $schedule->opcrf_year,
            'part_number' => $schedule->part_number,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    /**
     * A sentence describing what this entry did, for the audit table.
     */
    public function summary(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => 'Schedule created',
            self::ACTION_UPDATED => 'Schedule updated',
            self::ACTION_EXTENDED => 'Schedule extended',
            self::ACTION_ENABLED => 'Schedule enabled',
            self::ACTION_DISABLED => 'Schedule disabled',
            self::ACTION_DELETED => 'Schedule deleted',
            default => ucfirst($this->action),
        };
    }

    /**
     * The schedule this entry describes, when it still exists.
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OpcrfSchedule::class, 'opcrf_schedule_id');
    }

    /**
     * The superadmin who made the change, when their account still does.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
