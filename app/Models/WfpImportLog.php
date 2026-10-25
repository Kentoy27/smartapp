<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One committed bulk import of WFP master data: who imported, when, the
 * category and mode, the file name, and the result counts.
 *
 * `errors` keeps the row-level failures (line, values, reasons) so the
 * administrator can download the error report after the modal is gone — the
 * only thing stored from the uploaded file; the file itself is never kept.
 *
 * Append-only: `updated_at` is suppressed and only `created_at` is kept.
 */
class WfpImportLog extends Model
{
    public const UPDATED_AT = null;

    public const MODE_INSERT = 'insert';

    public const MODE_UPDATE = 'update';

    public const MODE_UPSERT = 'upsert';

    protected $fillable = [
        'user_id',
        'category',
        'mode',
        'filename',
        'total_rows',
        'inserted',
        'updated',
        'skipped',
        'failed',
        'errors',
    ];

    protected $casts = [
        'total_rows' => 'integer',
        'inserted' => 'integer',
        'updated' => 'integer',
        'skipped' => 'integer',
        'failed' => 'integer',
        'errors' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The row-level failures kept for the downloadable error report. */
    public function errorRows(): array
    {
        return is_array($this->errors) ? $this->errors : [];
    }
}
