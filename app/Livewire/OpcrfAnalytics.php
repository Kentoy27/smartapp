<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "OPCRF Analytics" — the superadmin's dashboard card.
 *
 * One glance at how the review workload is going: how many OPCRs came in,
 * how many are waiting on *this* superadmin, how many are signed off or
 * back with their staff member, the average self-rating, the split by
 * workflow status, and the newest few submissions with their badges.
 *
 * Every number is read through the same `visibleTo` boundary the review
 * list and the sidebar badge use — a superadmin sees their own queue's
 * analytics, not another reviewer's. The card polls, so a submission
 * approved on the Review Opcrf page shows up here without a reload.
 */
class OpcrfAnalytics extends Component
{
    public function mount(): void
    {
        // Superadmin-only card: the counts describe review queues, which
        // regular users must never see (not even as bare numbers).
        abort_unless(Auth::user()?->is_superadmin, 404);
    }

    public function render()
    {
        return view('livewire.opcrf.analytics');
    }

    /**
     * Every submission the signed-in superadmin may see.
     */
    #[Computed]
    public function total(): int
    {
        return $this->scope()->count();
    }

    /**
     * Sitting in this superadmin's queue, waiting on their action.
     */
    #[Computed]
    public function awaiting(): int
    {
        return OpcrfSubmission::awaitingReviewFrom(Auth::user())->count();
    }

    /**
     * Signed off: a final approval or a compliance mark.
     */
    #[Computed]
    public function approved(): int
    {
        return $this->scope()->approved()->count();
    }

    /**
     * Back with the staff member for revision.
     */
    #[Computed]
    public function returned(): int
    {
        return $this->scope()->where('status', OpcrfSubmission::STATUS_RETURNED)->count();
    }

    /**
     * The mean self-rating across the visible submissions. Every submission
     * carries one (the column is NOT NULL), so this is a plain average —
     * the card only shows it once it knows the queue is not empty.
     */
    #[Computed]
    public function averageRating(): float
    {
        return round((float) $this->scope()->avg('self_rating'), 2);
    }

    /**
     * The workflow split, one entry per status, as a share of the total:
     * `['status' => 'forwarded', 'label' => 'Forwarded', 'count' => 1,
     *   'percent' => 100.0, 'tone' => 'forwarded']`.
     *
     * Statuses with no rows are dropped (an empty legend entry teaches
     * nothing) and the rest keep the workflow's reading order, so the bar
     * does not reshuffle itself as counts change.
     */
    #[Computed]
    public function breakdown(): array
    {
        $counts = $this->scope()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return [];
        }

        $order = [
            OpcrfSubmission::STATUS_PENDING,
            OpcrfSubmission::STATUS_RESUBMITTED,
            OpcrfSubmission::STATUS_FORWARDED,
            OpcrfSubmission::STATUS_FOR_COMPLIANCE,
            OpcrfSubmission::STATUS_APPROVED,
            OpcrfSubmission::STATUS_RETURNED,
        ];

        return collect($order)
            ->filter(fn (string $status): bool => $counts->has($status))
            ->map(fn (string $status): array => [
                'status' => $status,
                'label' => OpcrfSubmission::statusLabel($status),
                'count' => (int) $counts->get($status),
                'percent' => round(((int) $counts->get($status) / $total) * 100, 1),
                'tone' => $status,
            ])
            ->values()
            ->all();
    }

    /**
     * The newest submissions, each with its owner loaded (the row shows who
     * filed it) — the "who is waiting on me" list under the numbers.
     */
    #[Computed]
    public function recent(): Collection
    {
        return $this->scope()
            ->with('user')
            ->latest('submitted_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * The submissions behind every number on the card.
     */
    private function scope(): Builder
    {
        return OpcrfSubmission::query()->visibleTo(Auth::user());
    }
}
