<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An append-only record of a generated WFP download.
 *
 * This stores export metadata only; signature bytes and workflow decisions
 * remain in the existing profile and WFP records.
 */
class WfpExportAudit extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'school_id',
        'school_division',
        'year',
        'wfp_plan_id',
        'template_version',
        'export_type',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public static function record(
        User $user,
        ?WfpPlan $plan,
        int $year,
        bool $includeSignatures,
    ): self {
        return static::query()->create([
            'user_id' => $user->id,
            'role' => $user->is_superadmin ? 'superadmin' : $user->role,
            'school_id' => $plan?->school_id ?? $user->school_id,
            'school_division' => $plan?->schoolDivision() ?? $user->activeDivision(),
            'year' => $plan?->year ?? $year,
            'wfp_plan_id' => $plan?->id,
            'template_version' => (string) ($plan?->template_version ?? config('wfp.template.version', '')),
            'export_type' => $includeSignatures ? 'signed' : 'unsigned',
        ]);
    }
}
