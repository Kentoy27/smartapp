<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One division a school operates.
 *
 * The rows are the school's menu of divisions: the user form only offers
 * these, and the server refuses a user assignment that names any other.
 */
class SchoolDivisionSetting extends Model
{
    protected $table = 'school_divisions';

    protected $fillable = [
        'school_id',
        'division_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
