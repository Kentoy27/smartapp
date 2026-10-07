<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Part of the MOV checklist ("PART 1").
 *
 * The catalogue is data, not code: parts, categories and requirements are
 * rows filled from config/mov.php by `php artisan mov:sync`, so the
 * checklist grows without a migration or a view change.
 */
class MovPart extends Model
{
    protected $fillable = [
        'name',
        'part_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'part_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Categories in checklist order. Only active ones: a retired category
     * disappears from the page but keeps its requirements (and anyone's
     * uploads against them) intact.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(MovCategory::class)->orderBy('category_order')->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * How many requirements this part holds, for the part header's count.
     */
    public function requirementsCount(): int
    {
        return MovRequirement::query()
            ->whereIn('mov_category_id', $this->categories()->select('id'))
            ->where('is_active', true)
            ->count();
    }
}
