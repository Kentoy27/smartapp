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

    public bool $isReplacingReturned = false;

    public bool $isReplacingExisting = false;

    public bool $isRestartingReview = false;

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
        $this->isReplacingReturned = false;
        $this->isReplacingExisting = false;
        $this->isRestartingReview = false;
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

        $existingPlan = AipPlan::query()
            ->where('school_id', $this->user()->school_id)
            ->where('year', $this->detectedYear)
            ->first();

        if ($existingPlan !== null && ! $existingPlan->managedBy($this->user())) {
            $this->addError('aipFile', "An AIP for FY {$this->detectedYear} belongs to another School Head account.");

            return;
        }

        $this->isReplacingReturned = $existingPlan?->isReturned() ?? false;
        $this->isReplacingExisting = $existingPlan !== null;
        $this->isRestartingReview = $existingPlan !== null
            && ! $this->isReplacingReturned
            && $existingPlan->submitted_at !== null;
        $replacementMessage = match (true) {
            $this->isReplacingReturned => 'This upload will replace the returned FY '.$this->detectedYear.' AIP and resubmit it for review.',
            $this->isRestartingReview => 'This upload will replace the submitted FY '.$this->detectedYear.' AIP, preserve its submission history, and restart review with the first reviewer.',
            $this->isReplacingExisting => 'This upload will replace the FY '.$this->detectedYear.' draft and submit it for review.',
            default => 'Review the preview before submitting.',
        };
        $this->analysisMessage = count($this->previewRows).' AIP row(s) found. '.$replacementMessage;
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

        $existingPlan = AipPlan::query()
            ->where('school_id', $user->school_id)
            ->where('year', $year)
            ->first();

        if ($existingPlan !== null && ! $existingPlan->managedBy($user)) {
            $this->addError('aipFile', "An AIP for FY {$year} belongs to another School Head account.");

            return null;
        }

        $storedPath = $this->aipFile->storeAs(
            'aip-uploads/'.$user->school_id,
            Str::uuid()->toString().'.docx',
            'local',
        );

        if (! is_string($storedPath)) {
            throw new RuntimeException('The uploaded AIP could not be saved. Please try again.');
        }

        try {
            $result = DB::transaction(function () use ($user, $year, $storedPath, $originalName, $imported): string {
                $plan = AipPlan::query()
                    ->where('school_id', $user->school_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if ($plan !== null) {
                    if (! $plan->managedBy($user)) {
                        return 'not-owner';
                    }

                    $isReturned = $plan->isReturned();

                    return $plan->replaceUpload($user, $imported['rows'], $storedPath, $originalName)
                        ? ($isReturned ? 'resubmitted' : 'restarted')
                        : 'locked';
                } else {
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
                        return 'locked';
                    }
                }

                $plan->wfp_plan_id = WfpPlan::query()
                    ->where('school_id', $user->school_id)
                    ->where('school_division', $user->activeDivision())
                    ->where('year', $year)
                    ->value('id');
                $plan->source_file_path = $storedPath;
                $plan->original_file_name = $originalName;
                $plan->form_data = ['rows' => $imported['rows']];
                $plan->save();
                $plan->submit($user);

                return 'submitted';
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }

        if ($result === 'not-owner' || $result === 'locked') {
            Storage::disk('local')->delete($storedPath);
            $this->addError(
                'aipFile',
                $result === 'not-owner'
                    ? "An AIP for FY {$year} belongs to another School Head account."
                    : "The FY {$year} AIP is no longer available for replacement. Please try uploading it again."
            );

            return null;
        }

        session()->flash(
            'status',
            match ($result) {
                'resubmitted' => "FY {$year} AIP updated and resubmitted for review.",
                'restarted' => "FY {$year} AIP replaced. Its submission history was preserved and review restarted.",
                default => "FY {$year} AIP submitted and is Pending Review.",
            }
        );

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
        $this->reset([
            'aipFile',
            'previewRows',
            'detectedYear',
            'originalName',
            'analysisMessage',
            'isReplacingReturned',
            'isReplacingExisting',
            'isRestartingReview',
        ]);
    }

    public function render()
    {
        return view('livewire.aip-submission-upload');
    }
}
