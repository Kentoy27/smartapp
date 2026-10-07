<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Live sidebar navigation.
 *
 * Rendered through Livewire so it reacts to app state without a full page
 * reload: the active item is highlighted with wire:current (kept in sync on
 * every navigate), and the component listens for events emitted by the
 * pages it links to — the Users table announces changes via `users-refreshed`.
 */
class Sidebar extends Component
{
    /**
     * Number of accounts, shown as a live badge on the Users item.
     */
    public ?int $usersCount = null;

    /**
     * Number of OPCRF submissions still awaiting the superadmin's approval,
     * shown as a live badge on the Review Opcrf item.
     */
    public ?int $opcrfCount = null;

    /**
     * The navigation items, listed alphabetically by label.
     *
     * The order is derived (not hand-written) so it stays predictable as soon
     * as a new item is added: role checks decide *which* items exist, the sort
     * decides where they appear.
     */
    #[Computed]
    public function items(): array
    {
        $items = [
            [
                'label' => 'Dashboard',
                'route' => 'home',
                'path' => 'home',
                'icon' => 'layout-dashboard',
            ],
        ];

        if (Auth::user()?->is_superadmin) {
            $items[] = [
                'label' => 'Users',
                'route' => 'users.index',
                'path' => 'users',
                'icon' => 'users-round',
                'badge' => $this->usersCount,
            ];

            $items[] = [
                'label' => 'Review Opcrf',
                'route' => 'opcrf.review',
                'path' => 'opcrf-review',
                'icon' => 'eye',
                'badge' => $this->opcrfCount,
            ];
        } else {
            // Opcrf is a staff-facing page: regular users only.
            $items[] = [
                'label' => 'Opcrf',
                'route' => 'opcrf.index',
                'path' => 'opcrf',
                'icon' => 'clipboard-list',
            ];

            // WFP is for the School Head (SH) role only — hidden from the
            // SDS Viewer, and unreachable by URL regardless (route guard).
            if (Auth::user()?->canAccessWfp()) {
                $items[] = [
                    'label' => 'WFP',
                    'route' => 'wfp.index',
                    'path' => 'wfp',
                    'icon' => 'chart-column',
                ];
            }
        }

        // Natural + case-insensitive so items slot in reading order and
        // numbered labels ("Report 2" before "Report 10") sort as a human reads.
        usort($items, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $items;
    }

    /**
     * Fired by the Users table component whenever its data changes.
     */
    #[On('users-refreshed')]
    public function refreshUsersBadge(): void
    {
        if (Auth::user()?->is_superadmin) {
            $this->usersCount = User::count();
        }
    }

    /**
     * Fired by the staff upload window the moment a submission is
     * confirmed, and by the superadmin's review modal once a submission is
     * approved — the badge updates without a reload.
     */
    #[On('opcrf-submission-created')]
    #[On('opcrf-submission-approved')]
    #[On('opcrf-submission-deleted')]
    #[On('opcrf-submission-forwarded')]
    public function refreshOpcrfBadge(): void
    {
        if (Auth::user()?->is_superadmin) {
            $this->opcrfCount = $this->pendingSubmissions();
        }
    }

    /**
     * Submissions still awaiting approval (what "Review Opcrf" is for).
     */
    private function pendingSubmissions(): int
    {
        return OpcrfSubmission::visibleTo(Auth::user())
            ->whereNull('approved_at')
            ->count();
    }

    public function render()
    {
        if (Auth::user()?->is_superadmin) {
            $this->usersCount = User::count();
            $this->opcrfCount = $this->pendingSubmissions();
        } else {
            $this->usersCount = null;
            $this->opcrfCount = null;
        }

        return view('livewire.sidebar');
    }
}
