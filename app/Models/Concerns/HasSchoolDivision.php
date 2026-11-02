<?php

namespace App\Models\Concerns;

use App\Support\SchoolDivision;

/**
 * Shared behavior for the rows that belong to one school division.
 *
 * Every such row stores `school_division`; legacy rows written before the
 * division columns existed read as Elementary because that is the migration's
 * default. `schoolDivision()` is the one place that fallback is applied, so
 * a freshly created model (whose database default has not been reloaded) is
 * never mistaken for a row that belongs to no division.
 */
trait HasSchoolDivision
{
    /** A new instance reads as Elementary until its stored value is loaded. */
    public function initializeHasSchoolDivision(): void
    {
        $this->attributes['school_division'] ??= SchoolDivision::ELEMENTARY;
    }

    public function schoolDivision(): string
    {
        $division = (string) ($this->school_division ?? '');

        return SchoolDivision::isValidDivision($division) ? $division : SchoolDivision::ELEMENTARY;
    }
}
