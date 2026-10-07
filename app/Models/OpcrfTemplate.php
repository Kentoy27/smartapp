<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One personalised OPCRF template generated for a staff member.
 *
 * The workbook is not stored: it is reproduced from the shipped template
 * plus the account whenever it is needed. This row is the record that it
 * happened — who it was for, which template version produced it, when, and
 * the file name the browser was given.
 *
 * An account's latest row is its current template; earlier rows are kept so
 * a submission can still be traced to the version of the form it was
 * actually answered on.
 */
class OpcrfTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'version',
        'filename',
        'revision',
    ];

    /**
     * Record a fresh download for this account, numbering it against the
     * ones before it so the history of "which form did I fill in, and when"
     * survives every re-download.
     */
    public static function record(User $user, string $version, string $filename): self
    {
        return self::create([
            'user_id' => $user->id,
            'version' => $version,
            'filename' => $filename,
            'revision' => ((int) static::where('user_id', $user->id)->max('revision')) + 1,
        ]);
    }

    /**
     * The account this template was generated for.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The submissions answered on this template.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(OpcrfSubmission::class, 'opcrf_template_id');
    }

    /**
     * The template version every new download is currently stamped with.
     */
    public static function currentVersion(): string
    {
        return (string) config('opcrf.template.version', '2026.1');
    }

    /**
     * This account's most recent template, or null when it has never
     * downloaded one.
     */
    public static function latestFor(User $user): ?self
    {
        return static::where('user_id', $user->id)
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->first();
    }
}
