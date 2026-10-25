<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the WFP lookup library's audit trail. Append-only: rows are
 * created when an administrative action happens and never updated, so
 * `updated_at` is suppressed and only `created_at` is kept.
 *
 * `auditable_type` is the category key ("funding_source", "unit", …,
 * "line_template") and `auditable_id` the row's id; both are plain columns,
 * not foreign keys, so an audit entry outlives the record it describes.
 */
class WfpLookupAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'user_id',
        'action',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
