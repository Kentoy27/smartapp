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
        $maxUploadKb = (int) config('sip.max_upload_kb', 102400);

        $school = School::query()
            ->with('divisionSettings')
            ->findOrFail($this->uploadSchoolId);
        abort_unless($this->canUploadTo($school, $this->uploadDivision), 403);

        $validated = $this->validate([
            'sipFile' => ['required', 'file', 'max:'.$maxUploadKb, 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx'],
        ], [
            'sipFile.required' => 'Choose the School Improvement Plan file first.',
            'sipFile.max' => 'The SIP file must be '.number_format($maxUploadKb / 1024, 0).' MB or smaller.',
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

        DB::transaction(function () use ($school, $user, $path, $validated): void {
            SipSubmission::create([
                'school_id' => $school->id,
                'division' => $this->uploadDivision,
                'uploaded_by' => $user->id,
                'original_file_name' => basename($validated['sipFile']->getClientOriginalName()),
                'file_path' => $path,
                'file_size' => $validated['sipFile']->getSize(),
                'mime_type' => $validated['sipFile']->getMimeType(),
                'uploaded_at' => now(),
            ]);
        });

        $schoolName = $school->name;
        $divisionName = SchoolDivision::label($this->uploadDivision);
        $this->closeUploadModal();
        $this->successMessage = "{$divisionName} SIP added for {$schoolName}.";
        unset($this->schools);
    }

    public function deleteSip(int $submissionId): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 403);

        $submission = SipSubmission::query()->findOrFail($submissionId);
        abort_unless(
            (int) $submission->school_id === (int) $user->school_id
                && $user->canAccessDivision($submission->division),
            404,
        );

        $filePath = $submission->file_path;
        $divisionName = SchoolDivision::label($submission->division);
        $submission->delete();

        if ($filePath !== '' && ! Storage::disk('local')->delete($filePath)) {
            throw new RuntimeException('The SIP record was deleted, but its stored file could not be removed.');
        }

        if ($this->previewSubmissionId === $submissionId) {
            $this->closePreviewModal();
        }

        $this->successMessage = "{$divisionName} SIP deleted.";
        unset($this->schools);
    }

    public function openBulkUploadModal(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 403);

        $divisions = $this->uploadableDivisions();
        abort_if($divisions === [], 403);

        $this->resetValidation();
        $defaultDivision = in_array($user->defaultDivision(), $divisions, true)
            ? $user->defaultDivision()
            : $divisions[0];
        $this->bulkUploads = [['division' => $defaultDivision, 'file' => null]];
        $this->showBulkUploadModal = true;
    }

    public function addBulkUploadRow(): void
    {
        $divisions = $this->uploadableDivisions();
        abort_if($divisions === [], 403);

        $user = Auth::user();
        $defaultDivision = $user instanceof User && in_array($user->defaultDivision(), $divisions, true)
            ? $user->defaultDivision()
            : $divisions[0];
        $this->bulkUploads[] = ['division' => $defaultDivision, 'file' => null];
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
        $maxUploadKb = (int) config('sip.max_upload_kb', 102400);
        $allowedDivisions = array_values(array_filter(
            $school->availableDivisions(),
            fn (string $division): bool => $this->canUploadTo($school, $division),
        ));
        abort_if($allowedDivisions === [], 403);

        $this->bulkUploads = array_values(array_filter(
            $this->bulkUploads,
            static fn (array $upload): bool => ! empty($upload['file']),
        ));

        if ($this->bulkUploads === []) {
            $this->addError('bulkUploads', 'Select at least one SIP document to upload.');

            return;
        }

        foreach ($this->bulkUploads as &$upload) {
            if (trim((string) ($upload['division'] ?? '')) === '') {
                $upload['division'] = in_array($user->defaultDivision(), $allowedDivisions, true)
                    ? $user->defaultDivision()
                    : $allowedDivisions[0];
            }
        }
        unset($upload);

        $validated = $this->validate([
            'bulkUploads' => ['required', 'array', 'min:1'],
            'bulkUploads.*.division' => ['required', 'string', Rule::in($allowedDivisions)],
            'bulkUploads.*.file' => ['required', 'file', 'max:'.$maxUploadKb, 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx'],
        ], [
            'bulkUploads.*.file.max' => 'Each SIP file must be '.number_format($maxUploadKb / 1024, 0).' MB or smaller.',
            'bulkUploads.*.file.mimes' => 'Upload PDF, Word, Excel, or PowerPoint documents.',
            'bulkUploads.*.file.extensions' => 'Upload PDF, Word, Excel, or PowerPoint documents.',
        ]);

        $storedPaths = [];
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

            DB::transaction(function () use ($validated, $storedPaths, $school, $user): void {
                foreach ($validated['bulkUploads'] as $index => $upload) {
                    SipSubmission::create([
                        'school_id' => $school->id,
                        'division' => $upload['division'],
                        'uploaded_by' => $user->id,
                        'original_file_name' => basename($upload['file']->getClientOriginalName()),
                        'file_path' => $storedPaths[$index],
                        'file_size' => $upload['file']->getSize(),
                        'mime_type' => $upload['file']->getMimeType(),
                        'uploaded_at' => now(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);

            throw $exception;
        }

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
