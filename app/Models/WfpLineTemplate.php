<?php

namespace App\Models;

use App\Models\Concerns\HasLookupBehavior;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Activity / Expenditure Template: a reusable combination of the fields a
 * WFP matrix line normally repeats. Selecting one in the Add a Line form
 * fills the fields it has a value for; the saved line keeps its own copy of
 * every value, so editing this template later never rewrites a saved plan.
 *
 * References to the lookup library are foreign keys (`unit_id` can only be a
 * unit); long-form text (objective, major output, performance indicator,
 * MOVs) is stored directly. `account_classification_id` points at the
 * existing RCA / UACS master table.
 *
 * `suggested_quantity` / `suggested_unit_cost` are SUGGESTIONS: the form
 * treats them as defaults and the School Head confirms them.
 *
 * One resource row per template for now; a `wfp_line_template_resources`
 * table can be added later without changing this one.
 */
class WfpLineTemplate extends Model
{
    use HasFactory;
    use HasLookupBehavior;

    public const LOOKUP_KEY = 'line_template';

    public const LINE_TYPE_OPERATIONS = 'operations';

    public const LINE_TYPE_PROJECT = 'project';

    protected $fillable = [
        'name',
        'description',
        'line_type',
        'objective',
        'activity_expenditure',
        'resource_kind_id',
        'unit_id',
        'suggested_quantity',
        'suggested_unit_cost',
        'account_classification_id',
        'funding_source_id',
        'org_classification_id',
        'major_output',
        'performance_indicator',
        'activity_movs',
        'tags',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'line_type' => 'string',
        'suggested_quantity' => 'decimal:2',
        'suggested_unit_cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Two templates are "the same" when their normalized name AND line type
     * match — a duplicate is a trap for the School Head choosing one.
     */
    protected static function duplicateColumns(): array
    {
        return ['name', 'line_type'];
    }

    protected static function duplicateRequiresAllColumns(): bool
    {
        return true;
    }

    public function resourceKind(): BelongsTo
    {
        return $this->belongsTo(WfpResourceKind::class, 'resource_kind_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(WfpUnit::class, 'unit_id');
    }

    public function accountClassification(): BelongsTo
    {
        return $this->belongsTo(AccountClassification::class, 'account_classification_id');
    }

    public function fundingSource(): BelongsTo
    {
        return $this->belongsTo(WfpFundingSource::class, 'funding_source_id');
    }

    public function orgClassification(): BelongsTo
    {
        return $this->belongsTo(WfpOrgClassification::class, 'org_classification_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The line type as the form reads it. */
    public function lineTypeLabel(): string
    {
        return $this->line_type === self::LINE_TYPE_PROJECT ? 'Project' : 'Operations';
    }

    /** The searchable one-line context shown beside the name in a picker. */
    public function pickerLabel(): string
    {
        $parts = [$this->name, $this->lineTypeLabel()];

        $funding = $this->fundingSource?->name;

        if (is_string($funding) && $funding !== '') {
            $parts[] = $funding;
        }

        $code = $this->accountClassification?->uacs_object_code;

        if (is_string($code) && $code !== '') {
            $parts[] = '['.$code.']';
        }

        return implode(' — ', $parts);
    }
}
