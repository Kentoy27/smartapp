<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One MOV requirement — the "MOV 1 / Approved WFP and AIP" line in the
 * checklist.
 *
 * Requirements are shared: every staff member sees the same catalogue, and
 * what they attach to a requirement is a UserMov row pointing back here. That
 * is what keeps a document attached to an exact MOV (User → Part → A → MOV 1
 * → file) instead of carrying copies of the part, category and title around
 * on the upload itself.
 */
class MovRequirement extends Model
{
    protected $fillable = [
        'mov_category_id',
        'mov_number',
        'title',
        'description',
        'is_required',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mov_number' => 'integer',
            'is_required' => 'boolean',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MovCategory::class, 'mov_category_id');
    }

    /**
     * Every staff member's upload against this requirement.
     */
    public function userMovs(): HasMany
    {
        return $this->hasMany(UserMov::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    /**
     * The label shown on every line of the page: "MOV 1".
     */
    public function label(): string
    {
        return 'MOV '.$this->mov_number;
    }

    /**
     * "Part 1 → A → MOV 1" — shown on the requirement card and on the
     * download's tooltip, so a file always says what it belongs to.
     */
    public function trail(): string
    {
        $segments = array_filter([$this->category?->part?->name, $this->category?->name, $this->label()]);

        return implode(' → ', $segments);
    }

    /**
     * What this requirement is for, as one sentence for the card's sub-line.
     */
    public function summary(): string
    {
        return trim((string) $this->description);
    }
}
