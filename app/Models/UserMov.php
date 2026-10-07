<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One staff member's document for one MOV requirement.
 *
 * This is the row that makes an uploaded MOV a record rather than a file:
 * it knows whose it is, which requirement it answers, what state it is in,
 * and — once a reviewer has looked at it — who decided what and why.
 *
 * The file itself never lives on a public path; `stored_path` is relative to
 * the configured disk and every read goes through a route that checks
 * ownership (see the mov.download / mov.view routes).
 */
class UserMov extends Model
{
    /** Just uploaded: no reviewer has looked at it yet. */
    public const STATUS_UPLOADED = 'uploaded';

    /** A reviewer has picked it up. */
    public const STATUS_UNDER_REVIEW = 'under_review';

    /** A reviewer accepted it. */
    public const STATUS_ACCEPTED = 'accepted';

    /** A reviewer sent it back; `remarks` says what to fix. */
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'user_id',
        'mov_requirement_id',
        'original_name',
        'stored_path',
        'mime_type',
        'size_bytes',
        'status',
        'remarks',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The stored file goes with the row — whether the person removed the
        // upload or an administrator retired the requirement.
        static::deleting(function (self $mov): void {
            Storage::disk(config('mov.disk', 'local'))->delete($mov->stored_path);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(MovRequirement::class, 'mov_requirement_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * The badge text. A row without a status (impossible in practice) reads
     * as Uploaded rather than as an empty badge.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADED => 'Uploaded',
            self::STATUS_UNDER_REVIEW => 'Under Review',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_RETURNED => 'Returned for Revision',
            default => 'Uploaded',
        };
    }

    /**
     * The badge colour, matching the OPCRF status badges.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACCEPTED => 'badge--ok',
            self::STATUS_RETURNED => 'badge--warn',
            self::STATUS_UNDER_REVIEW => 'badge--info',
            default => 'badge-muted',
        };
    }

    /**
     * The file is there but a reviewer asked for changes, so it needs the
     * owner's attention before anything else.
     */
    public function needsRevision(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /**
     * Whether the file is still on disk. A missing file is reported rather
     * than offered as a download that 500s.
     */
    public function fileExists(): bool
    {
        return $this->stored_path !== ''
            && Storage::disk(config('mov.disk', 'local'))->exists($this->stored_path);
    }

    /**
     * Human-readable size for the file line ("2.4 MB").
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

    /**
     * A reviewer accepted the document. Exposed now so the review workflow
     * can be added without touching this model again.
     */
    public function accept(?User $reviewer = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACCEPTED,
            'remarks' => null,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * A reviewer sent it back, with the reason. Replacing the file later
     * clears both the status and the remarks (see the upload flow), so a
     * returned item does not keep showing stale instructions.
     */
    public function returnForRevision(?User $reviewer, ?string $remarks = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_RETURNED,
            'remarks' => $remarks,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * Whether this account may read the file: its owner always, a
     * superadmin always (they are the reviewer), nobody else. The single
     * place both the view and download routes ask.
     */
    public function canBeAccessedBy(?User $user): bool
    {
        return $user !== null
            && ($this->user_id === $user->id || $user->is_superadmin);
    }

    /**
     * The name the download is offered under: the file the person uploaded,
     * with the requirement's label so a saved copy says what it is.
     */
    public function downloadName(): string
    {
        $name = trim((string) $this->original_name);

        return $name !== '' ? $name : 'mov-'.$this->mov_requirement_id.'.bin';
    }
}
