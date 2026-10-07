<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'employee_id',
        'role',
        'username',
        'email',
        'password',
        'is_superadmin',
        'google_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_superadmin' => 'boolean',
    ];

    /**
     * May this account use the WFP (Work and Financial Plan) module?
     *
     * The WFP feature is reserved for the School Head (SH) role: the SDS
     * Viewer and the Super Admin are both denied. In this app the SH role is
     * the `user` value (a null role is treated as SH everywhere, matching the
     * Users table), so access is "not a superadmin and not a viewer".
     *
     * This is the single source of truth for WFP authorization — used by the
     * page/route guards, the dashboard card, the sidebar item, and every
     * Livewire action.
     */
    public function canAccessWfp(): bool
    {
        return ! $this->is_superadmin && $this->role !== 'viewer';
    }
}
