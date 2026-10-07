<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $fillable = ['name'];

    public function schools(): HasMany
    {
        return $this->hasMany(School::class)->orderBy('name');
    }

    /**
     * The district's user-facing display name ("District I" keeps the
     * template's Roman-numeral style; raw names pass through untouched).
     */
    public function displayName(): string
    {
        return (string) $this->name;
    }
}
