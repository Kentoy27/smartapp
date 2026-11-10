<?php

namespace App\Livewire;

use App\Models\School;
use App\Models\SipSubmission;
use App\Models\User;
use App\Support\SchoolDivision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

class SipTable extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public int $perPage = 20;

    public bool $showUploadModal = false;

    public bool $showBulkUploadModal = false;

    /**
     * @var array<int, array{division: string, file: mixed}>
     */
    public array $bulkUploads = [];

    public ?int $previewSubmissionId = null;

    public ?int $uploadSchoolId = null;

    public string $uploadDivision = '';

    public $sipFile = null;

    public ?string $successMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessSip(), 404);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function schools(): LengthAwarePaginator
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->canAccessSip(), 404);

        return School::query()
            ->with(['district', 'divisionSettings', 'sipSubmissions.uploader'])
            ->when($user->role === 'user', fn (Builder $query) => $query->whereKey($user->school_id))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('school_id', 'like', $term)
                        ->orWhereHas('district', fn (Builder $district) => $district->where('name', 'like', $term));
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage);
    }

    /**
     * The divisions from this school's configured list that the signed-in
     * account is allowed to see.
     *
     * @return array<int, string>
     */
    public function visibleDivisions(School $school): array
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->canAccessSip(), 404);

        return array_values(array_filter(
            $school->availableDivisions(),
            fn (string $division): bool => $user->canAccessDivision($division),
        ));
    }

    public function canUploadTo(School $school, string $division): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->canManageWfp()
            && (int) $user->school_id === (int) $school->id
            && $user->canAccessDivision($division)
            && $school->offersDivision($division);
    }

    /**
     * @return array<int, string>
     */
    public function uploadableDivisions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->canManageWfp() || $user->school_id === null) {
            return [];
        }

        $school = School::query()->with('divisionSettings')->find($user->school_id);

        if (! $school) {
            return [];
        }

        return array_values(array_filter(
            $school->availableDivisions(),
            fn (string $division): bool => $this->canUploadTo($school, $division),
        ));
    }

    public function openPreview(int $submissionId): void
    {
        $submission = SipSubmission::query()->findOrFail($submissionId);
        abort_unless($submission->canBeDownloadedBy(Auth::user()), 404);
        abort_unless($submission->hasFile(), 404);
        abort_unless(
            in_array(strtolower(pathinfo($submission->downloadName(), PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true),
            415,
        );

        $this->previewSubmissionId = $submission->id;
        unset($this->previewSubmission);
    }

    public function closePreviewModal(): void
    {
        $this->previewSubmissionId = null;
        unset($this->previewSubmission);
    }

    #[Computed]
    public function previewSubmission(): ?SipSubmission
    {
        if ($this->previewSubmissionId === null) {
            return null;
        }

        $submission = SipSubmission::query()->findOrFail($this->previewSubmissionId);
        abort_unless($submission->canBeDownloadedBy(Auth::user()), 404);
        abort_unless($submission->hasFile(), 404);

        return $submission;
    }

    public function openUploadModal(int $schoolId, string $division): void
    {
        $school = School::query()->with('divisionSettings')->findOrFail($schoolId);
        abort_unless($this->canUploadTo($school, $division), 403);

        $this->resetValidation();
        $this->sipFile = null;
        $this->uploadSchoolId = $school->id;
        $this->uploadDivision = $division;
        $this->showUploadModal = true;
    }

    public function closeUploadModal(): void
    {
        $this->showUploadModal = false;
        $this->uploadSchoolId = null;
        $this->uploadDivision = '';
        $this->sipFile = null;
        $this->resetValidation();
    }

    public function uploadSip(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 403);

        $school = School::query()
            ->with('divisionSettings')
            ->findOrFail($this->uploadSchoolId);
        abort_unless($this->canUploadTo($school, $this->uploadDivision), 403);

        $validated = $this->validate([
            'sipFile' => ['required', 'file', 'max:12288', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx'],
        ], [
            'sipFile.required' => 'Choose the School Improvement Plan file first.',
            'sipFile.max' => 'The SIP file must be 12 MB or smaller.',
            'sipFile.mimes' => 'Upload a PDF, Word, Excel, or PowerPoint document.',
            'sipFile.extensions' => 'Upload a PDF, Word, Excel, or PowerPoint document.',
        ]);

        $extension = strtolower($validated['sipFile']->getClientOriginalExtension());
        $path = $validated['sipFile']->storeAs(
            'sip-uploads/'.$school->id.'/'.$this->uploadDivision,
            Str::uuid()->toString().'.'.$extension,
            'local',
        );

        if (! is_string($path)) {
            throw new RuntimeException('The SIP file could not be saved. Please try again.');
        }

        $previousPath = DB::transaction(function () use ($school, $user, $path, $validated): ?string {
            $submission = SipSubmission::query()
                ->where('school_id', $school->id)
                ->where('division', $this->uploadDivision)
                ->lockForUpdate()
                ->first();

            $previous = $submission?->file_path;

            SipSubmission::updateOrCreate(
                ['school_id' => $school->id, 'division' => $this->uploadDivision],
                [
                    'uploaded_by' => $user->id,
                    'original_file_name' => basename($validated['sipFile']->getClientOriginalName()),
                    'file_path' => $path,
                    'file_size' => $validated['sipFile']->getSize(),
                    'mime_type' => $validated['sipFile']->getMimeType(),
                    'uploaded_at' => now(),
                ],
            );

            return $previous;
        });

        if ($previousPath !== null && $previousPath !== $path) {
            Storage::disk('local')->delete($previousPath);
        }

        $schoolName = $school->name;
        $divisionName = SchoolDivision::label($this->uploadDivision);
        $this->closeUploadModal();
        $this->successMessage = "{$divisionName} SIP uploaded for {$schoolName}.";
        unset($this->schools);
    }

    public function openBulkUploadModal(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 403);

        $divisions = $this->uploadableDivisions();
        abort_if($divisions === [], 403);

        $this->resetValidation();
        $this->bulkUploads = [['division' => $divisions[0], 'file' => null]];
        $this->showBulkUploadModal = true;
    }

    public function addBulkUploadRow(): void
    {
        $divisions = $this->uploadableDivisions();
        abort_if($divisions === [], 403);

        $selectedDivisions = array_column($this->bulkUploads, 'division');
        $remainingDivisions = array_values(array_diff($divisions, $selectedDivisions));

        if ($remainingDivisions !== []) {
            $this->bulkUploads[] = ['division' => $remainingDivisions[0], 'file' => null];
        }
    }

    public function removeBulkUploadRow(int $index): void
    {
        if (count($this->bulkUploads) <= 1 || ! array_key_exists($index, $this->bulkUploads)) {
            return;
        }

        unset($this->bulkUploads[$index]);
        $this->bulkUploads = array_values($this->bulkUploads);
        $this->resetValidation();
    }

    public function closeBulkUploadModal(): void
    {
        $this->showBulkUploadModal = false;
        $this->bulkUploads = [];
        $this->resetValidation();
    }

    public function uploadMultipleSip(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp() && $user->school_id !== null, 403);

        $school = School::query()
            ->with('divisionSettings')
            ->findOrFail($user->school_id);
        $allowedDivisions = array_values(array_filter(
            $school->availableDivisions(),
            fn (string $division): bool => $this->canUploadTo($school, $division),
        ));
        abort_if($allowedDivisions === [], 403);

        $validated = $this->validate([
            'bulkUploads' => ['required', 'array', 'min:1', 'max:'.count($allowedDivisions)],
            'bulkUploads.*.division' => ['required', 'string', 'distinct:strict', Rule::in($allowedDivisions)],
            'bulkUploads.*.file' => ['required', 'file', 'max:12288', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx'],
        ], [
            'bulkUploads.*.division.distinct' => 'Choose a different division for each SIP.',
            'bulkUploads.*.file.required' => 'Choose a SIP document for each division.',
            'bulkUploads.*.file.max' => 'Each SIP file must be 12 MB or smaller.',
            'bulkUploads.*.file.mimes' => 'Upload PDF, Word, Excel, or PowerPoint documents.',
            'bulkUploads.*.file.extensions' => 'Upload PDF, Word, Excel, or PowerPoint documents.',
        ]);

        $storedPaths = [];
        $previousPaths = [];

        try {
            foreach ($validated['bulkUploads'] as $upload) {
                $extension = strtolower($upload['file']->getClientOriginalExtension());
                $path = $upload['file']->storeAs(
                    'sip-uploads/'.$school->id.'/'.$upload['division'],
                    Str::uuid()->toString().'.'.$extension,
                    'local',
                );

                if (! is_string($path)) {
                    throw new RuntimeException('A SIP file could not be saved. Please try again.');
                }

                $storedPaths[] = $path;
            }

            DB::transaction(function () use ($validated, $storedPaths, $school, $user, &$previousPaths): void {
                foreach ($validated['bulkUploads'] as $index => $upload) {
                    $submission = SipSubmission::query()
                        ->where('school_id', $school->id)
                        ->where('division', $upload['division'])
                        ->lockForUpdate()
                        ->first();

                    if ($submission?->file_path) {
                        $previousPaths[] = $submission->file_path;
                    }

                    SipSubmission::updateOrCreate(
                        ['school_id' => $school->id, 'division' => $upload['division']],
                        [
                            'uploaded_by' => $user->id,
                            'original_file_name' => basename($upload['file']->getClientOriginalName()),
                            'file_path' => $storedPaths[$index],
                            'file_size' => $upload['file']->getSize(),
                            'mime_type' => $upload['file']->getMimeType(),
                            'uploaded_at' => now(),
                        ],
                    );
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

        Storage::disk('local')->delete(array_values(array_diff($previousPaths, $storedPaths)));

        $uploadedCount = count($storedPaths);
        $this->closeBulkUploadModal();
        $this->successMessage = "{$uploadedCount} SIP ".($uploadedCount === 1 ? 'file was' : 'files were')." uploaded for {$school->name}.";
        unset($this->schools);
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.sip.table');
    }
}
