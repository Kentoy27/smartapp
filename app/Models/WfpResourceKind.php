<?php

namespace App\Models;

use App\Models\Concerns\HasLookupBehavior;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resource kind a WFP line may name (e.g. MOOE, or other locally
 * approved resource types). Administrator-managed master data.
 *
 * Retired with `is_active = false`, never deleted: a historical line or an
 * activity template that points at one stays readable, while new selections
 * only ever offer active rows.
 */
class WfpResourceKind extends Model
{
    use HasFactory;
    use HasLookupBehavior;

    public const LOOKUP_KEY = 'resource_kind';

    protected $fillable = [
        'code',
        'name',
        'description',
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
