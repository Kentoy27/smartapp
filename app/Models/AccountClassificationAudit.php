<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a classification's audit trail. Append-only: rows are created
 * when an administrative action happens and never updated, so `updated_at` is
 * suppressed and only `created_at` is kept.
 */
class AccountClassificationAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'account_classification_id',
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

    public function classification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class, 'account_classification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
