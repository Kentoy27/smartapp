<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Support\OpcrfSubmissionWorkbook;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class OpcrfViewerSubmissions extends Component
{
    use WithPagination;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canReviewAllOpcrfSubmissions(), 404);
    }

    public ?int $viewingId = null;

    public string $search = '';

    public function paginationView(): string
    {
        return 'livewire.users.partials.pagination';
    }

    /**
     * Submitted OPCRFs belonging to School Head accounts, with their
     * assigned school and district for identification.
     *
     * @return LengthAwarePaginator<int, OpcrfSubmission>
     */
    #[Computed]
    public function submissions(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return OpcrfSubmission::query()
            ->whereHas('user', fn (Builder $user): Builder => $user
                ->where('role', 'user')
                ->where('is_superadmin', false))
            ->when($search !== '', function (Builder $submissions) use ($search): void {
                $term = '%'.$search.'%';

                $submissions->where(function (Builder $matches) use ($term): void {
                    $matches
                        ->where('employee_name', 'like', $term)
                        ->orWhereHas('user', fn (Builder $user): Builder => $user
                            ->where('name', 'like', $term)
                            ->orWhere('username', 'like', $term)
                            ->orWhereHas('school', fn (Builder $school): Builder => $school
                                ->where('name', 'like', $term)
                                ->orWhereHas('district', fn (Builder $district): Builder => $district
                                    ->where('name', 'like', $term))));
                });
            })
            ->with(['user.school.district', 'latestReview'])
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(15);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function openSubmission(int $submissionId): void
    {
        abort_unless(Auth::user()?->canReviewAllOpcrfSubmissions(), 404);

        abort_unless(
            OpcrfSubmission::query()
                ->whereKey($submissionId)
                ->whereHas('user', fn (Builder $user): Builder => $user
                    ->where('role', 'user')
                    ->where('is_superadmin', false))
                ->exists(),
            404
        );

        $this->viewingId = $submissionId;
        unset($this->viewingSubmission, $this->viewingSheet, $this->viewingWorkbook);
    }

    public function closeSubmission(): void
    {
        $this->viewingId = null;
        unset($this->viewingSubmission, $this->viewingSheet, $this->viewingWorkbook);
    }

    #[Computed]
    public function viewingSubmission(): ?OpcrfSubmission
    {
        if (! Auth::user()?->canReviewAllOpcrfSubmissions() || $this->viewingId === null) {
            return null;
        }

        return OpcrfSubmission::query()
            ->whereKey($this->viewingId)
            ->whereHas('user', fn (Builder $user): Builder => $user
                ->where('role', 'user')
                ->where('is_superadmin', false))
            ->with('user.school.district')
            ->first();
    }

    #[Computed]
    public function viewingSheet(): ?array
    {
        $submission = $this->viewingSubmission();

        return $submission === null ? null : OpcrfSubmissionWorkbook::sheet($submission);
    }

    #[Computed]
    public function viewingWorkbook(): ?array
    {
        $submission = $this->viewingSubmission();

        if ($submission === null || $this->viewingSheet === null) {
            return null;
        }

        return OpcrfSubmissionWorkbook::workbook($submission);
    }

    public function render()
    {
        return view('livewire.opcrf.viewer-submissions');
    }
}
