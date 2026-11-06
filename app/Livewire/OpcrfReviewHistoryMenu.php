<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class OpcrfReviewHistoryMenu extends Component
{
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->is_superadmin, 404);
    }

    /**
     * Recent submissions visible to this reviewer, with their complete
     * immutable review trail.
     *
     * @return Collection<int, OpcrfSubmission>
     */
    #[Computed]
    public function historySubmissions(): Collection
    {
        $reviewer = Auth::user();
        abort_unless($reviewer instanceof User && $reviewer->is_superadmin, 404);

        return OpcrfSubmission::query()
            ->visibleTo($reviewer)
            ->whereHas('reviews')
            ->with(['user', 'reviews.reviewer', 'reviews.to'])
            ->withMax('reviews', 'reviewed_at')
            ->orderByDesc('reviews_max_reviewed_at')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.opcrf-review-history-menu');
    }
}
