<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category inside a Part ("A", "B", "C").
 */
class MovCategory extends Model
{
    protected $table = 'mov_categories';

    protected $fillable = [
        'mov_part_id',
        'name',
        'category_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MovPart::class, 'mov_part_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(MovRequirement::class)->orderBy('display_order')->orderBy('mov_number');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * "Part 1 → A" — the breadcrumb every requirement sits under.
     */
    public function trail(): string
    {
        $segments = array_filter([$this->part?->name, $this->name]);

        return implode(' → ', $segments);
    }
}
