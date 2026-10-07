<?php

namespace App\Livewire;

use App\Models\WfpSubmission;
use App\Support\WfpSpreadsheet;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /wfp module: download the template, see the uploaded plan's status,
 * preview its contents, and download or remove it.
 *
 * Only the School Head (SH) role may reach this component — enforced in
 * mount() and re-checked on every action. The stored workbook is served
 * through an authenticated route (wfp.download), never from public/.
 */
#[Title('Work and Financial Plan — SmartApp')]
class WfpManager extends Component
{
    /**
     * Preview filter term (program/activity/objective text).
     */
    public string $search = '';

    public ?string $successMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    /**
     * The signed-in user's current WFP (most recent), if any.
     */
    #[Computed]
    public function submission(): ?WfpSubmission
    {
        return WfpSubmission::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The workbook's persisted full-sheet analysis and row data. Older WFP
     * records without analysis are analyzed from their archived file.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $submission = $this->submission;

        if ($submission === null) {
            return null;
        }

        if (is_array($submission->sheet_data) && $submission->sheet_data !== []
            && is_array($submission->analysis) && is_array($submission->validation)) {
            return [
                'sheets' => $submission->sheet_data,
                'analysis' => $submission->analysis,
                'validation' => $submission->validation,
            ];
        }

        if (! $submission->hasFile()) {
            return ['error' => 'The uploaded workbook is missing from storage, so it cannot be analyzed.'];
        }

        try {
            $sheet = new WfpSpreadsheet(Storage::disk('local')->path($submission->file_path));

            return $sheet->reviewData();
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Every worksheet with its rows filtered by the optional search term.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function previewRows(): array
    {
        $preview = $this->preview;

        if ($preview === null) {
            return [];
        }

        if (isset($preview['error'])) {
            return [];
        }

        $term = trim($this->search);

        return array_map(function (array $sheet) use ($term): array {
            if ($term !== '') {
                $sheet['rows'] = array_values(array_filter($sheet['rows'], function (array $row) use ($term): bool {
                    foreach ($row['cells'] as $cell) {
                        if ($cell !== '' && mb_stripos($cell, $term) !== false) {
                            return true;
                        }
                    }

                    return false;
                }));
            }

            return $sheet;
        }, $preview['sheets'] ?? []);
    }

    /**
     * Fired after an upload (from the card or the page): refresh the status
     * and preview without a reload.
     */
    #[On('wfp-uploaded')]
    public function refresh(): void
    {
        unset($this->submission, $this->preview, $this->previewRows);
    }

    /**
     * Remove the current WFP (row + stored file).
     */
    public function deleteSubmission(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $submission = $this->submission;

        if ($submission === null || ! $submission->canBeManagedBy(Auth::user())) {
            return;
        }

        $submission->delete(); // model hook removes the stored file

        unset($this->submission, $this->preview, $this->previewRows);
        $this->search = '';
        $this->successMessage = 'WFP removed — you can upload a new one any time.';
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.wfp.manager', [
            'fileName' => WfpTemplate::fileName(),
            'templateDescription' => WfpTemplate::description(),
        ]);
    }
}
