<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One School Improvement Project of an AIP: its title, its objective and the
 * output for the year — the first three columns of the official form.
 *
 * Its activities live in aip_activities.
 */
class AipProject extends Model
{
    protected $fillable = [
        'aip_plan_id',
        'position',
        'title',
        'objective',
        'output_for_year',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AipPlan::class, 'aip_plan_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(AipActivity::class, 'aip_project_id')->orderBy('position')->orderBy('id');
    }

    /** The project's own budget: the sum of its activities' budgets. */
    public function budgetTotal(): float
    {
        return round((float) $this->activities()->sum('budget'), 2);
    }
}
