<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SipSubmission extends Model
{
    protected $fillable = [
        'school_id',
        'division',
        'uploaded_by',
        'original_file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function hasFile(): bool
    {
        return $this->file_path !== ''
            && Storage::disk('local')->exists($this->file_path);
    }

    public function downloadName(): string
    {
        $name = basename($this->original_file_name);

        return $name !== '' ? $name : 'School Improvement Plan';
    }

    public function canBeDownloadedBy(?User $user): bool
    {
        if ($user === null || ! $user->canAccessSip() || ! $user->canAccessDivision($this->division)) {
            return false;
        }

        return $user->role !== 'user' || (int) $user->school_id === (int) $this->school_id;
    }
}
