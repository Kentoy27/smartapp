<?php

namespace App\Livewire;

use App\Models\WfpSubmission;
use App\Support\WfpSpreadsheet;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * "Upload your WFP" trigger that lives on the dashboard's WFP Template card
 * and on the /wfp page.
 *
 * Clicking the trigger opens a small upload window; the staff member (School
 * Head) picks their completed WFP .xlsx there. The workbook is opened and
 * checked server-side (WfpSpreadsheet::validateStructure) before anything is
 * stored — a file that is not a WFP is rejected with a clear message and
 * nothing is saved.
 *
 * Access is restricted to the SH role: the component is mounted on pages an
 * SH alone can reach, and every action re-checks canAccessWfp() as well.
 */
#[Title('Dashboard — SmartApp')]
class WfpUpload extends Component
{
    use WithFileUploads;

    /**
     * The uploaded .xlsx (temporary, until validated and stored).
     */
    public $file;

    /**
     * The upload window's open state.
     */
    public bool $showUpload = false;

    /**
     * Success toast after a stored upload (cleared via clearSuccess()).
     */
    public ?string $successMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
    }

    /**
     * Clicked the card's Upload trigger: pop up the upload window.
     */
    public function openUpload(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $this->resetValidation();
        $this->file = null;
        $this->showUpload = true;
    }

    /**
     * Dismissed the upload window without picking a file.
     */
    public function closeUpload(): void
    {
        $this->showUpload = false;
        $this->file = null;
        $this->resetValidation();
    }

    /**
     * A file was picked: validate it, check it is a WFP, then store it.
     */
    public function updatedFile(): void
    {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        $maxKb = (int) config('wfp.max_size_kb', 10240);

        $this->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'extensions:xlsx'],
        ], [
            'file.required' => 'Choose your completed WFP .xlsx file first.',
            'file.extensions' => 'The WFP must be an .xlsx Excel file.',
            'file.max' => 'The file is too large ('.round($maxKb / 1024, 1).' MB maximum).',
        ]);

        try {
            $this->storeUpload();
        } catch (RuntimeException $e) {
            $this->file = null;
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->showUpload = false;
        $this->file = null;
        $this->resetValidation();
        $this->successMessage = 'WFP uploaded — your Work and Financial Plan was saved.';

        // The /wfp page's manager listens so its status/preview refresh live.
        $this->dispatch('wfp-uploaded')->to(WfpManager::class);
        $this->dispatch('wfp-uploaded');
    }

    /**
     * Validate the workbook, archive it, and record/replace the row.
     */
    private function storeUpload(): void
    {
        $user = Auth::user();

        // Open + structure-check the workbook before storing anything.
        $spreadsheet = new WfpSpreadsheet($this->file->getRealPath());
        $spreadsheet->validateStructure();
        $reviewData = $spreadsheet->reviewData();

        $originalName = $this->file->getClientOriginalName();

        $path = $this->file->storeAs(
            'wfp/'.$user->id,
            uniqid().'-'.$originalName,
            'local'
        );

        $submission = WfpSubmission::create([
            'user_id' => $user->id,
            'original_file_name' => $originalName,
            'file_path' => $path,
            'file_size' => $this->file->getSize(),
            'mime_type' => $this->file->getMimeType(),
            'file_type' => 'xlsx',
            'school_year' => $spreadsheet->detectSchoolYear(),
            'school_name' => $spreadsheet->detectSchoolName(),
            'status' => WfpSubmission::STATUS_UPLOADED,
            'uploaded_at' => now(),
            'sheet_data' => $reviewData['sheet_data'],
            'analysis' => $reviewData['analysis'],
            'validation' => $reviewData['validation'],
        ]);

        // Keep the just-created analyzed workbook intact if a cleanup failure
        // occurs while removing this user's previous upload.
        WfpSubmission::where('user_id', $user->id)
            ->where('id', '!=', $submission->id)
            ->get()
            ->each
            ->delete();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.wfp.upload');
    }
}
