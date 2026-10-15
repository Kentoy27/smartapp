<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
use App\Models\UserMov;
use App\Models\WfpSubmission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * System-wide counts for administrators and superadmins.
 */
class AdminDashboardStats extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 404);
    }

    #[Computed]
    public function schools(): int
    {
        return School::query()->count();
    }

    #[Computed]
    public function schoolHeads(): int
    {
        return User::query()
            ->where('role', 'user')
            ->where('is_superadmin', false)
            ->count();
    }

    #[Computed]
    public function opcrfSubmissions(): int
    {
        return OpcrfSubmission::query()->count();
    }

    #[Computed]
    public function wfpUploads(): int
    {
        return WfpSubmission::query()->count();
    }

    #[Computed]
    public function movUploads(): int
    {
        return UserMov::query()->count();
    }

    public function render()
    {
        return view('livewire.dashboard.admin-stats');
    }
}
