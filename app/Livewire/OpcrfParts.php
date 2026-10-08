<?php

namespace App\Livewire;

use App\Support\OpcrfAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The staff member's OPCRF parts grid.
 *
 * One card per Part of the form, each in the state the superadmin's schedule
 * puts it in: open, scheduled (waiting), closed, disabled, or not scheduled
 * at all — plus "completed" once the user has submitted that Part.
 *
 * The state is computed on the server (OpcrfAccess) and merely rendered
 * here. A locked Part is never hidden: it is listed with the reason and the
 * time it opens, because "it isn't there" is a worse answer than "not yet,
 * and here's when". The Access button is only emitted for a Part the server
 * says can be opened, and the route it points at re-checks on the way in —
 * the button is a convenience, not the control.
 */
#[Title('Opcrf — SmartApp')]
class OpcrfParts extends Component
{
    /**
     * Refresh the cards so a Part that opens while the page sits open flips
     * from locked to open without the user reloading. Every fifteen seconds
     * is plenty for a schedule measured in days.
     */
    public function render()
    {
        $user = Auth::user();

        abort_if($user === null || $user->hasAdminAccess(), 404);

        $year = OpcrfAccess::currentYear();
        $now = now();
        $parts = OpcrfAccess::allParts($user, $year, $now);

        return view('livewire.opcrf.parts', [
            'year' => $year,
            'parts' => $parts,
            'now' => $now,
            'openCount' => count(array_filter($parts, fn (array $p): bool => $p['open'])),
        ]);
    }
}
