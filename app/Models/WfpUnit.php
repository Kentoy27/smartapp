<?php

namespace App\Models;

use App\Models\Concerns\HasLookupBehavior;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of measure a WFP line may be counted in (e.g. ream, piece, set,
 * box, lot). Administrator-managed master data.
 *
 * `abbreviation` is the short form ("pc", "rm") the form may show beside the
 * full name. Retired with `is_active = false`, never deleted.
 */
class WfpUnit extends Model
{
    use HasFactory;
    use HasLookupBehavior;

    public const LOOKUP_KEY = 'unit';

    protected $fillable = [
        'code',
        'name',
        'abbreviation',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function duplicateColumns(): array
    {
        return ['name', 'code'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
