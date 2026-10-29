<?php

namespace App\Livewire;

use App\Models\OpcrfReview;
use App\Models\OpcrfSubmission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class OpcrfTasksMenu extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessStaffPages(), 404);
    }

    #[On('opcrf-submission-created')]
    #[On('opcrf-submission-approved')]
    #[On('opcrf-submission-archived')]
    #[On('opcrf-submission-restored')]
    public function refreshTasks(): void
    {
        unset($this->returnedTasks);
    }

    #[Computed]
    public function returnedTasks(): array
    {
        return OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->whereNull('archived_at')
            ->where('status', OpcrfSubmission::STATUS_RETURNED)
            ->with('latestReview.reviewer')
            ->latest('submitted_at')
            ->get()
            ->map(function (OpcrfSubmission $submission): array {
                $completion = $submission->reviewer_task_completion ?? [];
                $tasks = [];

                if (
                    $submission->latestReview?->action === OpcrfReview::ACTION_RETURN
                    && trim((string) $submission->latestReview?->remarks) !== ''
                ) {
                    $tasks[] = [
                        'key' => 'general',
                        'label' => 'General feedback',
                        'remark' => $submission->latestReview->remarks,
                        'completed' => (bool) ($completion['general'] ?? false),
                    ];
                }

                foreach ($submission->reviewer_remarks ?? [] as $partIndex => $objectives) {
                    foreach ($objectives as $objectiveIndex => $remark) {
                        if (! is_string($remark) || trim($remark) === '') {
                            continue;
                        }

                        $tasks[] = [
                            'key' => 'objective:'.$partIndex.':'.$objectiveIndex,
                            'label' => 'Part '.((int) $partIndex + 1).' — Objective '.((int) $objectiveIndex + 1),
                            'remark' => $remark,
                            'completed' => (bool) ($completion['objectives'][$partIndex][$objectiveIndex] ?? false),
                        ];
                    }
                }

                return [
                    'submission' => $submission,
                    'tasks' => $tasks,
                    'completed' => collect($tasks)->where('completed', true)->count(),
                ];
            })
            ->all();
    }

    public function toggleTask(int $submissionId, string $taskKey): void
    {
        $submission = OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->whereNull('archived_at')
            ->where('status', OpcrfSubmission::STATUS_RETURNED)
            ->with('latestReview')
            ->findOrFail($submissionId);

        $completion = $submission->reviewer_task_completion ?? [];

        if ($taskKey === 'general') {
            abort_unless(
                $submission->latestReview?->action === OpcrfReview::ACTION_RETURN
                    && trim((string) $submission->latestReview?->remarks) !== '',
                404
            );

            $completion['general'] = ! (bool) ($completion['general'] ?? false);
        } elseif (preg_match('/\Aobjective:(\d+):(\d+)\z/', $taskKey, $matches) === 1) {
            $partIndex = (int) $matches[1];
            $objectiveIndex = (int) $matches[2];
            $remark = $submission->reviewer_remarks[$partIndex][$objectiveIndex] ?? null;

            abort_unless(is_string($remark) && trim($remark) !== '', 404);

            $completion['objectives'][$partIndex][$objectiveIndex] =
                ! (bool) ($completion['objectives'][$partIndex][$objectiveIndex] ?? false);
        } else {
            abort(404);
        }

        $submission->forceFill(['reviewer_task_completion' => $completion])->save();
        unset($this->returnedTasks);
    }

    public function completeTasks(int $submissionId): void
    {
        $submission = OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->whereNull('archived_at')
            ->where('status', OpcrfSubmission::STATUS_RETURNED)
            ->with('latestReview')
            ->findOrFail($submissionId);

        $completion = $submission->reviewer_task_completion ?? [];

        if (
            $submission->latestReview?->action === OpcrfReview::ACTION_RETURN
            && trim((string) $submission->latestReview?->remarks) !== ''
        ) {
            $completion['general'] = true;
        }

        foreach ($submission->reviewer_remarks ?? [] as $partIndex => $objectives) {
            foreach ($objectives as $objectiveIndex => $remark) {
                if (is_string($remark) && trim($remark) !== '') {
                    $completion['objectives'][$partIndex][$objectiveIndex] = true;
                }
            }
        }

        $submission->forceFill(['reviewer_task_completion' => $completion])->save();
        unset($this->returnedTasks);
    }

    public function render()
    {
        return view('livewire.opcrf-tasks-menu');
    }
}
