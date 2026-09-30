<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OpcrfMov extends Model
{
    protected $fillable = [
        'opcrf_submission_id',
        'original_name',
        'stored_path',
        'size_bytes',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(OpcrfSubmission::class, 'opcrf_submission_id');
    }

    /**
     * Human-readable file size for the modal's file rows.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = (float) $this->size_bytes;

        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }

    protected static function booted(): void
    {
        // Delete the stored file with the row.
        static::deleting(function (self $mov): void {
            Storage::disk('local')->delete($mov->stored_path);
        });
    }
}
