<?php

namespace App\Support;

use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpReportCheck;
use RuntimeException;

final class WfpReportSignoffs
{
    public static function check(User $user, int $schoolId, int $year, string $division): WfpReportCheck
    {
        abort_unless($user->canCheckWfpReport(), 404);
        $school = self::schoolFor($user, $schoolId, $division);

        $hasLines = MonthlyExpenditureMatrix::query()
            ->where('school_id', $school->id)
            ->where('school_division', $division)
            ->where('year', $year)
            ->whereHas('items')
            ->exists();

        if (! $hasLines) {
            throw new RuntimeException('There are no expenditure lines for '.$school->name.' in CY '.$year.' yet, so there is nothing to check.');
        }

        return WfpReportCheck::query()->updateOrCreate(
            [
                'school_id' => $school->id,
                'school_division' => $division,
                'year' => $year,
            ],
            ['checked_by' => $user->id, 'checked_at' => now()],
        );
    }

    public static function certify(User $user, int $schoolId, int $year, string $division): SchoolAllotment
    {
        abort_unless($user->canCertifyAllotments(), 404);
        $school = self::schoolFor($user, $schoolId, $division);

        $allotment = SchoolAllotment::query()
            ->where('school_id', $school->id)
            ->where('school_division', $division)
            ->where('year', $year)
            ->first();

        if ($allotment === null) {
            throw new RuntimeException('Set the '.$school->name.' allotment for CY '.$year.' before certifying it as available.');
        }

        if ($allotment->isCertified()) {
            throw new RuntimeException($school->name.'’s CY '.$year.' allotment is already certified.');
        }

        $allotment->forceFill([
            'certified_by' => $user->id,
            'certified_at' => now(),
        ])->save();

        return $allotment;
    }

    private static function schoolFor(User $user, int $schoolId, string $division): School
    {
        abort_unless($user->canAccessDivision($division), 404);

        $school = School::query()->findOrFail($schoolId);
        abort_unless($user->canAccessSchool($school->id), 404);
        abort_unless($school->offersDivision($division), 404);

        return $school;
    }
}
