<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of Account Classification master data: an account title together
 * with its RCA / UACS codes and Covering PPAS.
 *
 * This is reference data: it is never hard-deleted. Retiring a classification
 * sets `is_active = false`, which hides it from new selections while leaving
 * any historical record that points at it intact (see the Activate/Deactivate
 * actions). Codes are stored as strings so leading zeroes survive.
 */
class AccountClassification extends Model
{
    use HasFactory;

    /** Audit actions recorded in account_classification_audits. */
    public const AUDIT_CREATE = 'CREATE';

    public const AUDIT_UPDATE = 'UPDATE';

    public const AUDIT_DELETE = 'DELETE';

    public const AUDIT_ACTIVATE = 'ACTIVATE';

    public const AUDIT_DEACTIVATE = 'DEACTIVATE';

    protected $fillable = [
        'account_title',
        'rca_code',
        'uacs_sub_object_code',
        'uacs_object_code',
        'covering_ppas',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function audits(): HasMany
    {
        return $this->hasMany(AccountClassificationAudit::class);
    }

    /** Only the classifications offered for new records. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Only the retired classifications — kept for history, hidden by default. */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Does a live classification already carry this title + code combination?
     *
     * Comparison is case- and whitespace-insensitive so a stray space cannot
     * slip a duplicate past the check. Retired rows are ignored: deactivating a
     * classification frees its identity to be re-added.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function duplicateExists(array $attributes, ?int $ignoreId = null): bool
    {
        return static::query()
            ->active()
            ->whereRaw('LOWER(TRIM(account_title)) = ?', [mb_strtolower(trim((string) ($attributes['account_title'] ?? '')))])
            ->whereRaw('LOWER(TRIM(rca_code)) = ?', [mb_strtolower(trim((string) ($attributes['rca_code'] ?? '')))])
            ->whereRaw('LOWER(TRIM(uacs_sub_object_code)) = ?', [mb_strtolower(trim((string) ($attributes['uacs_sub_object_code'] ?? '')))])
            ->whereRaw('LOWER(TRIM(uacs_object_code)) = ?', [mb_strtolower(trim((string) ($attributes['uacs_object_code'] ?? '')))])
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Append one entry to this classification's audit trail.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordAudit(string $action, ?User $user, ?array $before, ?array $after): void
    {
        $this->audits()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * The values written to an audit row — every field that can change, so a
     * "before" snapshot can be diffed against the "after" one.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'account_title' => $this->account_title,
            'rca_code' => $this->rca_code,
            'uacs_sub_object_code' => $this->uacs_sub_object_code,
            'uacs_object_code' => $this->uacs_object_code,
            'covering_ppas' => $this->covering_ppas,
            'is_active' => $this->is_active,
        ];
    }
}
