<?php

namespace App\Livewire;

use App\Models\AipPlan;
use App\Models\User;
use App\Models\WfpPlan;
use App\Support\AipTemplateImporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

#[Title('Annual Implementation Plan — SmartApp')]
class AipSubmissionUpload extends Component
{
    use WithFileUploads;

    public bool $showModal = false;

    public $aipFile = null;

    /** @var array<int, array<string, string>> */
    public array $previewRows = [];

    public ?int $detectedYear = null;

    public ?string $originalName = null;

    public ?string $analysisMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessAip(), 404);
    }

    public function openModal(): void
    {
        $this->user();
        $this->resetUpload();
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetUpload();
        $this->resetValidation();
    }

    public function updatedAipFile(): void
    {
        $this->previewRows = [];
        $this->detectedYear = null;
        $this->originalName = null;
        $this->analysisMessage = null;
        $this->resetValidation('aipFile');

        if (! $this->aipFile instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate([
            'aipFile' => ['required', 'file', 'extensions:docx', 'max:'.(int) config('aip.max_kb', 10240)],
        ], [
            'aipFile.extensions' => 'Choose an AIP Word document in DOCX format.',
            'aipFile.max' => 'The AIP document must be no larger than '.number_format((int) config('aip.max_kb', 10240) / 1024, 1).' MB.',
        ]);

        try {
            $imported = AipTemplateImporter::import($this->aipFile->getRealPath());
        } catch (RuntimeException $exception) {
            $this->addError('aipFile', $exception->getMessage());

            return;
        }

        if ($imported['rows'] === []) {
            $this->addError('aipFile', 'No AIP table rows were found in this document. Choose a valid AIP template; it may be submitted before every field is complete.');

            return;
        }

        $this->previewRows = $imported['rows'];
        $this->detectedYear = $imported['detected_year'] ?? (int) now()->format('Y');
        $this->originalName = $this->aipFile->getClientOriginalName();

        if ($this->user()->school_id === null) {
            $this->addError('aipFile', 'Your account is not attached to a school yet. Ask an administrator to assign your school.');

            return;
        }

        if (AipPlan::query()
            ->where('school_id', $this->user()->school_id)
            ->where('year', $this->detectedYear)
            ->exists()) {
            $this->addError('aipFile', "An AIP already exists for FY {$this->detectedYear}. Existing plans are not replaced by an upload.");

            return;
        }

        $this->analysisMessage = count($this->previewRows).' AIP row(s) found. Review the preview before submitting.';
    }

    public function submit(): mixed
    {
        $user = $this->user();

        if (! $this->aipFile instanceof TemporaryUploadedFile) {
            $this->addError('aipFile', 'Choose and analyze an AIP document before submitting.');

            return null;
        }

        $this->validate([
            'aipFile' => ['required', 'file', 'extensions:docx', 'max:'.(int) config('aip.max_kb', 10240)],
        ]);

        if ($user->school_id === null) {
            $this->addError('aipFile', 'Your account is not attached to a school yet. Ask an administrator to assign your school.');

            return null;
        }

        try {
            $imported = AipTemplateImporter::import($this->aipFile->getRealPath());
        } catch (RuntimeException $exception) {
            $this->addError('aipFile', $exception->getMessage());

            return null;
        }

        if ($imported['rows'] === []) {
            $this->addError('aipFile', 'No AIP table rows were found in this document. Choose a valid AIP template; it may be submitted before every field is complete.');

            return null;
        }

        $year = $imported['detected_year'] ?? (int) now()->format('Y');
        $originalName = $this->aipFile->getClientOriginalName();
        $storedPath = $this->aipFile->storeAs(
            'aip-uploads/'.$user->school_id,
            Str::uuid()->toString().'.docx',
            'local',
        );

        if (! is_string($storedPath)) {
            throw new RuntimeException('The uploaded AIP could not be saved. Please try again.');
        }

        try {
            $created = DB::transaction(function () use ($user, $year, $storedPath, $originalName, $imported): bool {
                $plan = AipPlan::firstOrCreate(
                    ['school_id' => $user->school_id, 'year' => $year],
                    [
                        'title' => 'FY '.$year.' AIP',
                        'form_data' => ['rows' => $imported['rows']],
                        'status' => AipPlan::STATUS_DRAFT,
                        'created_by' => $user->id,
                    ],
                );

                if (! $plan->wasRecentlyCreated) {
                    return false;
                }

                $plan->wfp_plan_id = WfpPlan::query()
                    ->where('school_id', $user->school_id)
                    ->where('school_division', $user->activeDivision())
                    ->where('year', $year)
                    ->value('id');
                $plan->source_file_path = $storedPath;
                $plan->original_file_name = $originalName;
                $plan->save();
                $plan->submit($user);

                return true;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }

        if (! $created) {
            Storage::disk('local')->delete($storedPath);
            $this->addError('aipFile', "An AIP already exists for FY {$year}. Existing plans are not replaced by an upload.");

            return null;
        }

        session()->flash('status', "FY {$year} AIP submitted and is Pending Review.");

        return $this->redirect(route('aip.index'), navigate: true);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessAip(), 404);

        return $user;
    }

    private function resetUpload(): void
    {
        $this->reset(['aipFile', 'previewRows', 'detectedYear', 'originalName', 'analysisMessage']);
    }

    public function render()
    {
        return view('livewire.aip-submission-upload');
    }
}
