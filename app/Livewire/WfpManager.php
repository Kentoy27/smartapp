<?php

namespace App\Livewire;

use App\Models\WfpSubmission;
use App\Support\WfpSpreadsheet;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
     * A read-only preview of the uploaded workbook (title + used-range grid),
     * or null when nothing is uploaded / the file can no longer be read.
     *
     * @return array{title: string, grid: array{rows: array<int, array{number: int, cells: array<int, string>}>, columns: int, header_index: ?int, truncated: bool}}|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $submission = $this->submission;

        if ($submission === null || ! $submission->hasFile()) {
            return null;
        }

        try {
            $sheet = new WfpSpreadsheet(Storage::disk('local')->path($submission->file_path));

            return ['title' => $sheet->title(), 'grid' => $sheet->grid()];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The preview rows after the optional search filter.
     *
     * @return array<int, array{number: int, cells: array<int, string>}>
     */
    #[Computed]
    public function previewRows(): array
    {
        $preview = $this->preview;

        if ($preview === null) {
            return [];
        }

        $rows = $preview['grid']['rows'];
        $term = trim($this->search);

        if ($term === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($term): bool {
            foreach ($row['cells'] as $cell) {
                if ($cell !== '' && mb_stripos($cell, $term) !== false) {
                    return true;
                }
            }

            return false;
        }));
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
