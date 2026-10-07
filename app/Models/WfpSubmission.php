<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An uploaded Work and Financial Plan workbook.
 *
 * Like the OPCRF submissions, the file is archived on the `local` disk only
 * (never public/) and reached through an authenticated route; this model
 * holds the metadata the WFP page shows and owns the stored file's lifetime.
 */
class WfpSubmission extends Model
{
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';

    protected $fillable = [
        'user_id',
        'original_file_name',
        'file_path',
        'file_size',
        'mime_type',
        'school_year',
        'school_name',
        'status',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null
            && Storage::disk('local')->exists($this->file_path);
    }

    /**
     * The name the file is served under — the original name, sanitized, with
     * a "- WFP" suffix so it is recognisable in the downloads folder.
     */
    public function fileDownloadName(): string
    {
        $base = pathinfo((string) $this->original_file_name, PATHINFO_FILENAME);
        $name = trim(preg_replace('#[\\\\/]+#', ' ', (string) $base) ?? '');

        return ($name !== '' ? $name : 'WFP').' - WFP.xlsx';
    }

    /**
     * Human-readable size ("1.2 MB"), "—" when unknown.
     */
    public function getHumanSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;

        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($value, $unit === 0 ? 0 : 1), '0'), '.').' '.$units[$unit];
    }

    /**
     * Authorization boundary: only the owner may manage (download/remove)
     * their WFP. Mirrors OpcrfSubmission::canBeManagedBy().
     */
    public function canBeManagedBy(?User $user): bool
    {
        return $user !== null && $this->user_id === $user->id;
    }

    /**
     * Deleting the row deletes the stored file. Done with a model hook (not
     * a DB cascade) so the file on disk is never orphaned.
     */
    protected static function booted(): void
    {
        static::deleting(function (WfpSubmission $submission): void {
            if ($submission->file_path !== null) {
                Storage::disk('local')->delete($submission->file_path);
            }
        });
    }
}
