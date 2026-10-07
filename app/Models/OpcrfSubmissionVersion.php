<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archived copy of a submission's workbook. Version 1 is the original
 * upload; every resubmission archives the workbook it replaces as the next
 * version, so no submitted copy is ever overwritten or lost.
 */
class OpcrfSubmissionVersion extends Model
{
    protected $fillable = [
        'opcrf_submission_id',
        'version_number',
        'stored_path',
        'original_name',
        'submitted_at',
        'status',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(OpcrfSubmission::class, 'opcrf_submission_id');
    }

    /**
     * "Version 1" style label.
     */
    public function label(): string
    {
        return 'Version '.$this->version_number;
    }
}
