<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity of an AIP project — the row of the spreadsheet editor:
 * the activity itself, who is responsible, the schedule/venue, the budget
 * per activity and the budget source.
 */
class AipActivity extends Model
{
    protected $fillable = [
        'aip_plan_id',
        'aip_project_id',
        'position',
        'activity',
        'person_responsible',
        'schedule',
        'venue',
        'budget',
        'budget_source',
    ];

    protected $casts = [
        'position' => 'integer',
        'budget' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AipPlan::class, 'aip_plan_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(AipProject::class, 'aip_project_id');
    }

    /** "Quarter 1 – Gymnasium" style label, tolerant of missing halves. */
    public function scheduleLabel(): string
    {
        $schedule = trim((string) $this->schedule);
        $venue = trim((string) $this->venue);

        return match (true) {
            $schedule !== '' && $venue !== '' => $schedule.' — '.$venue,
            $schedule !== '' => $schedule,
            $venue !== '' => $venue,
            default => '—',
        };
    }
}
