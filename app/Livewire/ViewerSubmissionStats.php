<?php

namespace App\Livewire;

use App\Models\AipPlan;
use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Counts submitted School Head documents visible in the Viewer's review areas.
 */
class ViewerSubmissionStats extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->canReviewAllOpcrfSubmissions(), 404);
    }

    /**
     * Current submissions and their workflow stages across AIP, OPCRF, and WFP.
     *
     * @return array{
     *     total: int,
     *     aip: int,
     *     opcrf: int,
     *     wfp: int,
     *     awaiting: int,
     *     in_review: int,
     *     returned: int,
     *     approved: int
     * }
     */
    #[Computed]
    public function stats(): array
    {
        $viewer = Auth::user();
        abort_unless($viewer instanceof User && $viewer->canReviewAllOpcrfSubmissions(), 404);

        $aip = $this->statusCounts(
            AipPlan::query()->visibleTo($viewer)->whereNotNull('submitted_at'),
            'status',
        );
        $opcrf = $this->statusCounts(
            OpcrfSubmission::query()
                ->whereHas('user', fn (Builder $user): Builder => $user
                    ->where('role', 'user')
                    ->where('is_superadmin', false))
                ->whereNotNull('submitted_at'),
            'status',
        );
        $wfp = $this->statusCounts(
            WfpSubmission::query()->where('school_division', $viewer->activeDivision()),
            'review_status',
        );

        $stages = ['awaiting' => 0, 'in_review' => 0, 'returned' => 0, 'approved' => 0];

        foreach ([$aip, $opcrf, $wfp] as $statuses) {
            foreach ($statuses as $status => $count) {
                $stage = match ($status) {
                    'returned' => 'returned',
                    'approved', 'for_compliance' => 'approved',
                    'forwarded', 'in_review', 'under_review' => 'in_review',
                    default => 'awaiting',
                };
                $stages[$stage] += $count;
            }
        }

        $aipCount = $aip->sum();
        $opcrfCount = $opcrf->sum();
        $wfpCount = $wfp->sum();

        return [
            'total' => $aipCount + $opcrfCount + $wfpCount,
            'aip' => $aipCount,
            'opcrf' => $opcrfCount,
            'wfp' => $wfpCount,
            ...$stages,
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Collection<string, int>
     */
    private function statusCounts(Builder $query, string $column): Collection
    {
        return $query
            ->selectRaw($column.' as workflow_status, count(*) as aggregate')
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) ($row->workflow_status ?? '') => (int) $row->aggregate]);
    }

    public function render()
    {
        return view('livewire.dashboard.viewer-submission-stats');
    }
}
