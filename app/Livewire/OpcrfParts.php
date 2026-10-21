<?php

namespace App\Livewire;

use App\Support\OpcrfAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The staff member's OPCRF parts grid.
 *
 * Part 1 has its own card; Parts 2–4 share one card with individual actions
 * and states. Each state reflects what the superadmin's schedule allows:
 * open, scheduled (waiting), closed, disabled, or completed after submission.
 *
 * The state is computed on the server (OpcrfAccess) and merely rendered
 * here. A locked Part is never hidden, because "it isn't there" is a worse
 * answer than "not yet". An Access link is only emitted for a Part the server
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

        abort_unless($user?->canViewOpcrfParts(), 404);

        $year = OpcrfAccess::currentYear();
        $readOnly = $user->role === 'viewer';
        if ($readOnly) {
            return view('livewire.opcrf.parts', [
                'year' => $year,
                'parts' => [],
                'now' => now(),
                'openCount' => 0,
                'readOnly' => true,
            ]);
        }

        $now = now();
        $parts = OpcrfAccess::allParts($user, $year, $now);
        $groupStates = array_values(array_filter(
            $parts,
            fn (array $part): bool => in_array($part['part'], [2, 3, 4], true),
        ));
        $displayParts = array_values(array_filter(
            $parts,
            fn (array $part): bool => in_array($part['part'], [1, 2], true),
        ));

        foreach ($displayParts as &$part) {
            $part['is_grouped_term'] = $part['part'] === 2;
            $part['group_states'] = $part['is_grouped_term'] ? $groupStates : [];
            $part['all_group_parts_complete'] = $part['is_grouped_term']
                && count($groupStates) === 3
                && collect($groupStates)->every(fn (array $state): bool => $state['completed']);
            $part['card_tone'] = $part['all_group_parts_complete'] ? 'completed' : $part['tone'];
        }
        unset($part);

        return view('livewire.opcrf.parts', [
            'year' => $year,
            'parts' => $displayParts,
            'now' => $now,
            'openCount' => count(array_filter($parts, fn (array $p): bool => $p['open'])),
            'readOnly' => false,
        ]);
    }
}
