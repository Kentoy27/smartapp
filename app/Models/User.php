<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'position',
        'role',
        'username',
        'email',
        'password',
        'is_superadmin',
        'google_id',
        'school_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_superadmin' => 'boolean',
    ];

    /**
     * The school this account belongs to (District → School → User) —
     * null for superadmins and accounts created before a school was
     * assigned.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
