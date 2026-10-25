<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\WfpLookupAudit;
use Illuminate\Database\Eloquent\Builder;

/**
 * The behaviour every WFP lookup master table shares: active/inactive scopes,
 * case/whitespace-insensitive duplicate detection, a resolver for imports and
 * template references, and the audit trail hook.
 *
 * Every model using this trait declares its own `LOOKUP_KEY` (the category
 * key written to `wfp_lookup_audits.auditable_type`) and may override
 * `duplicateColumns()` / `duplicateRequiresAllColumns()` to say what makes
 * two rows "the same record":
 * - lookups (funding sources, units, …): same name OR same code is a
 *   duplicate;
 * - activity templates: same name AND line type.
 */
trait HasLookupBehavior
{
    /** Audit actions recorded in wfp_lookup_audits. */
    public const AUDIT_CREATE = 'CREATE';

    public const AUDIT_UPDATE = 'UPDATE';

    public const AUDIT_ACTIVATE = 'ACTIVATE';

    public const AUDIT_DEACTIVATE = 'DEACTIVATE';

    /** Only the records offered for new selections. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Only the retired records — kept for history, hidden by default. */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * The columns that identify a row for duplicate detection. Overridden by
     * the models that carry a code (or a line type).
     *
     * @return array<int, string>
     */
    protected static function duplicateColumns(): array
    {
        return ['name'];
    }

    /**
     * Whether every duplicate column must match (AND) or any one of them (OR).
     * Lookups: any. Templates: all.
     */
    protected static function duplicateRequiresAllColumns(): bool
    {
        return false;
    }

    /**
     * Does a live row already carry this identity? Comparison is case- and
     * whitespace-insensitive, and empty values are ignored, so a row without
     * a code can never "match" another row without one. Retired rows are
     * ignored: deactivating a record frees its identity to be re-added.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function duplicateExists(array $attributes, ?int $ignoreId = null): bool
    {
        $pairs = [];

        foreach (static::duplicateColumns() as $column) {
            $value = mb_strtolower(trim((string) ($attributes[$column] ?? '')));

            if ($value !== '') {
                $pairs[$column] = $value;
            }
        }

        if ($pairs === []) {
            return false;
        }

        return static::query()
            ->active()
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as $column => $value) {
                    $method = static::duplicateRequiresAllColumns() ? 'whereRaw' : 'orWhereRaw';

                    $query->{$method}("LOWER(TRIM({$column})) = ?", [$value]);
                }
            })
            ->exists();
    }

    /**
     * Find the row an import row or template reference points at: by code
     * when the value carries one (the stable identifier — a display name is
     * never used to match when a code is available), otherwise by name.
     * Retired rows are matched too, so an import can update a record that is
     * currently inactive.
     */
    public static function findMatch(string $code, string $name): ?static
    {
        $code = mb_strtolower(trim($code));

        if ($code !== '') {
            return static::query()
                ->whereRaw('LOWER(TRIM(code)) = ?', [$code])
                ->orderBy('id')
                ->first();
        }

        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return null;
        }

        return static::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$name])
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve a value written in a template's reference column to a live row:
     * matched by code first, then by name, among active records only. Used by
     * the activity-template importer, which must never attach a new template
     * to a retired record.
     */
    public static function resolveActive(string $value): ?static
    {
        $needle = mb_strtolower(trim($value));

        if ($needle === '') {
            return null;
        }

        return static::query()
            ->active()
            ->where(function (Builder $query) use ($needle): void {
                $query->whereRaw('LOWER(TRIM(code)) = ?', [$needle])
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', [$needle]);
            })
            // A code match is the more precise one: prefer it when a value
            // matches one row's code and another row's name.
            ->orderByRaw('CASE WHEN LOWER(TRIM(code)) = ? THEN 0 ELSE 1 END', [$needle])
            ->orderBy('id')
            ->first();
    }

    /**
     * The values written to an audit row — every field that can change, so a
     * "before" snapshot can be diffed against the "after" one. The audit
     * columns themselves are excluded.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        $fields = array_values(array_diff($this->getFillable(), ['created_by', 'updated_by']));

        return $this->only($fields);
    }

    /**
     * Append one entry to this record's audit trail.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function recordLookupAudit(string $action, ?User $user, ?array $before, ?array $after): void
    {
        WfpLookupAudit::create([
            'auditable_type' => static::LOOKUP_KEY,
            'auditable_id' => $this->getKey(),
            'user_id' => $user?->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
