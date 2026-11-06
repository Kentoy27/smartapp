<?php

namespace App\Models;

use App\Support\SchoolDivision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $fillable = ['district_id', 'name', 'school_id'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The divisions this school operates, in canonical interface order.
     *
     * A school can offer any combination of Elementary, Junior High and
     * Senior High — an integrated school offers all three. A school with no
     * configuration rows keeps every division available: rows created before
     * this configuration existed (or outside the administrator's form) must
     * not lock their existing accounts out. A configured school whose rows
     * were all switched off keeps the historical Elementary default rather
     * than becoming unusable.
     *
     * @return array<int, string>
     */
    public function availableDivisions(): array
    {
        $settings = $this->relationLoaded('divisionSettings')
            ? $this->divisionSettings
            : $this->divisionSettings()->get();

        if ($settings->isEmpty()) {
            return SchoolDivision::all();
        }

        $active = SchoolDivision::validAccessList(
            $settings->where('is_active', true)->pluck('division_code')->all(),
        );

        return $active !== [] ? $active : [SchoolDivision::ELEMENTARY];
    }

    /** Whether this school operates the division. */
    public function offersDivision(string $division): bool
    {
        return in_array($division, $this->availableDivisions(), true);
    }

    public function divisionSettings(): HasMany
    {
        return $this->hasMany(SchoolDivisionSetting::class);
    }
}
