<?php

namespace App\Livewire;

use App\Models\District;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The District List — every district with its school count.
 *
 * Superadmin-only, and rendered in two places: as the dashboard's summary
 * card and as the whole of the Districts & Schools page behind the sidebar
 * item. Both go through this one component so the two views can never drift,
 * and it polls so school counts stay current wherever it appears.
 *
 * The DistrictManager modal is rendered alongside the card because the card's
 * buttons are what open it — keeping the pair together is what lets the same
 * markup work on the dashboard and on its own page.
 */
class DistrictList extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->is_superadmin, 404);
    }

    public function render()
    {
        return view('livewire.districts.list', [
            // The same query the DistrictManager modal edits, so the counts
            // here can never disagree with the schools it manages.
            'districts' => District::query()->withCount('schools')->orderBy('name')->get(),
        ]);
    }
}
