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

    /**
     * Whether this account has access to administrative workflows.
     */
    public function hasAdminAccess(): bool
    {
        return $this->is_superadmin || $this->role === 'administrator';
    }

    /** Whether this account may manage accounts and school organization. */
    public function canManageUsersAndSchools(): bool
    {
        return $this->role === 'administrator' && ! $this->is_superadmin;
    }

    public function roleLabel(): string
    {
        if ($this->is_superadmin || $this->role === 'superadmin') {
            return 'Super Admin';
        }

        return match ($this->role) {
            'administrator' => 'Administrator',
            'viewer' => 'SDS Viewer',
            default => 'SH',
        };
    }

    /**
     * Whether this account may access the Work and Financial Plan (WFP)
     * module. WFP is for the School Head (SH) role only: the role column
     * uses 'user' for SH (the UI labels it 'SH'), 'viewer' for the SDS
     * Viewer, and 'superadmin' for superadmins. Only a non-superadmin SH
     * can reach WFP — everyone else gets a 404 from the route guard.
     */
    public function canAccessWfp(): bool
    {
        return ! $this->is_superadmin && $this->role === 'user';
    }
}
