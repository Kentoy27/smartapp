<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Bookkeeper's check of one school's year of account titles and codes —
 * the "Checked as to the Correctness of Account Titles and Codes" signatory
 * on the annual MOOE allotment report.
 *
 * The check belongs to the school and year it covers (the report is derived
 * from that school's Monthly Expenditure Matrices), so it is stored per
 * school per year, never per account. When the year's lines are written the
 * check no longer describes what was checked, so the writing surfaces clear
 * it (see clearFor()) and the Bookkeeper checks the new lines on their own.
 */
class WfpReportCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'year',
        'checked_by',
        'checked_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** The Bookkeeper account that checked the report. */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * Whether this row carries a valid check. Both halves must be present: a
     * row whose checker account was deleted has a date but no one who stands
     * behind it, which is not a check.
     */
    public function isChecked(): bool
    {
        return $this->checked_by !== null && $this->checked_at !== null;
    }

    /**
     * Clear the check for one school and year, if any.
     *
     * Calling this is the write surfaces' way of saying "the lines this
     * signed off on have changed". A check that no longer describes the
     * lines must not keep printing a name on them.
     */
    public static function clearFor(?int $schoolId, ?int $year): void
    {
        if ($schoolId === null || $year === null) {
            return;
        }

        static::query()
            ->where('school_id', $schoolId)
            ->where('year', $year)
            ->delete();
    }
}
