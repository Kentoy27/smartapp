<?php

namespace App\Livewire;

use App\Models\OpcrfMov;
use App\Models\OpcrfSubmission;
use App\Models\User;
use App\Support\OpcrfSpreadsheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * "Upload your OPCR" trigger that lives on the dashboard's OPCRF Template
 * card.
 *
 * Clicking the trigger opens the "Upload your OPCR in here" window; the
 * staff member picks their filled-in OPCRF-TEMPLATE.xlsx there. The file
 * is analyzed server-side (OpcrfSpreadsheet) and the upload window hands
 * over to a "Review before submitting" modal showing every extracted
 * detail — nothing is stored until the user confirms inside that modal.
 *
 * Confirming stores the submission and immediately pops up a locked
 * "Upload your MOVs" window: the staff member must attach at least one
 * Means of Verification before they can continue — that window has no
 * close button and cannot be dismissed via ESC or a backdrop click.
 */
#[Title('Dashboard — SmartApp')]
class OpcrfUpload extends Component
{
    use WithFileUploads;

    /**
     * The uploaded .xlsx (temporary, until analyzed).
     */
    public $file;

    /**
     * "Upload your OPCR in here" window, opened from the OPCRF Template
     * card's trigger (openUpload()).
     */
    public bool $showUpload = false;

    /**
     * "Review before submitting" modal state — populated from the analyzed
     * workbook. Rendering gated on $analyzed so the modal only ever shows
     * freshly-analyzed data.
     */
    public bool $showReview = false;

    public bool $analyzed = false;

    public string $employee_name = '';

    public string $position = '';

    public string $review_period = '';

    public string $division_office = '';

    public string $objectives = '';

    public string $accomplishments = '';

    public string $self_rating = '';

    /**
     * Excel-style sheet rendering of the analyzed workbook (review modal).
     * Populated together with the scalar fields in analyzeAndFill() from
     * OpcrfSpreadsheet's layout methods (title / headerCells /
     * evaluatorCells / parts / signers), so the modal can show the form
     * the way it looks in the actual Excel template — the evaluator
     * block beside the staff details, one table per part (I-A, I-B, I-C)
     * with its total-score row, and the signer block at the end.
     *
     * @var array{title: string, headers: array<int, array{ref: string, label: string, value: string}>, evaluators: array<int, array{ref: string, label: string, value: string}>, parts: array<int, array{key: string, title: string, note: string, rows: array<int, array{row: int, number: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>, total_score: ?float, has_total_row: bool}>, signers: array<int, array{ref: string, role: string, name: string}>}
     */
    public array $sheet = ['title' => '', 'headers' => [], 'evaluators' => [], 'parts' => [], 'signers' => []];

    /**
     * Success toast after a confirmed upload (clearSuccess pattern).
     */
    public ?string $successMessage = null;

    /**
     * Locked "Upload your MOVs" window — pops up right after the reviewed
     * OPCR is confirmed. It cannot be dismissed: it only releases once at
     * least one MOV file has been attached and Continue is clicked.
     */
    public bool $showMovsModal = false;

    /**
     * The submission the locked MOVs window belongs to (set at confirm
     * time). It is resolved through movSubmission(), which re-checks
     * ownership on every call.
     */
    public ?int $movSubmissionId = null;

    public string $movSubmissionLabel = '';

    /**
     * True once at least one MOV file has been attached inside the locked
     * window — that is what unlocks the Continue button.
     */
    public bool $movsSaved = false;

    /**
     * Newly-picked MOV files (multiple). Livewire temp-uploads them; they
     * are moved into permanent storage immediately in updatedMovFiles().
     */
    public $movFiles = [];

    /**
     * The superadmin this OPCR is being sent to (the review modal's picker).
     * Only that account sees the submission afterwards.
     */
    public $reviewer_id = '';

    /**
     * Superadmin accounts a staff member can send their OPCR to, oldest
     * first by username.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function reviewers(): Collection
    {
        return User::query()
            ->where('is_superadmin', true)
            ->orderBy('username')
            ->get(['id', 'username', 'name']);
    }

    /**
     * Rules for the review modal's confirm step. Blanks are approved:
     * whatever the spreadsheet did or didn't contain gets recorded, with
     * empty strings for unfilled text and self_rating stored as 0.00
     * when the template carried no ratings (shown as “—” in the table).
     *
     * The recipient is required and must be a superadmin — the server, not
     * the picker, is what enforces it.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'employee_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'review_period' => ['nullable', 'string', 'max:255'],
            'division_office' => ['nullable', 'string', 'max:255'],
            'objectives' => ['nullable', 'string'],
            'accomplishments' => ['nullable', 'string'],
            // Only guard against nonsense: a value the analyzer could
            // never have produced.
            'self_rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'reviewer_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('is_superadmin', true)
                ),
            ],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'employee_name' => 'name of employee',
            'position' => 'position/designation',
            'review_period' => 'review period',
            'division_office' => 'division/office',
            'objectives' => 'objectives',
            'accomplishments' => 'accomplishments',
            'self_rating' => 'self rating',
            'reviewer_id' => 'superadmin to send this OPCR to',
        ];
    }

    public function mount(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);
    }

    /**
     * The submission the locked MOVs window is attached to. Null unless it
     * exists AND the signed-in user owns it — the authorization boundary
     * for every MOV action in this flow (same gate as OpcrfMovs).
     */
    public function movSubmission(): ?OpcrfSubmission
    {
        if ($this->movSubmissionId === null) {
            return null;
        }

        $submission = OpcrfSubmission::find($this->movSubmissionId);

        if ($submission === null || ! $submission->canBeManagedBy(Auth::user())) {
            return null;
        }

        return $submission;
    }

    /**
     * Clicked the template card's trigger: pop up the upload window.
     */
    public function openUpload(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $this->resetValidation();
        $this->resetMovs();
        $this->ensureReviewerSelected();
        $this->showUpload = true;
    }

    /**
     * Pre-select the first superadmin so the usual single-admin setup is one
     * click; the staff member can pick another one in the picker. Stays blank
     * when no superadmin account exists — the submit then fails validation
     * with an explanation instead of routing to nobody.
     */
    private function ensureReviewerSelected(): void
    {
        if ($this->reviewer_id !== '' && $this->reviewer_id !== null) {
            return;
        }

        $this->reviewer_id = (string) ($this->reviewers->first()?->id ?? '');
    }

    /**
     * Clear the locked-MOVs step (used defensively when the flow restarts).
     */
    private function resetMovs(): void
    {
        $this->showMovsModal = false;
        $this->movSubmissionId = null;
        $this->movSubmissionLabel = '';
        $this->movsSaved = false;
        $this->movFiles = [];
    }

    /**
     * Dismissed the upload window without picking a file: drop anything
     * temporary so the next open starts clean.
     */
    public function closeUpload(): void
    {
        $this->showUpload = false;
        $this->file = null;
        $this->resetValidation();
    }

    /**
     * Uploaded → analyze the workbook → close the upload window and open
     * the review modal over the dashboard.
     */
    public function updatedFile(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $this->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx'],
        ], [
            'file.required' => 'Choose your filled-in OPCRF .xlsx file first.',
            'file.extensions' => 'The OPCRF must be the .xlsx template file.',
            'file.max' => 'The file is too large (10 MB maximum).',
        ]);

        try {
            $this->analyzeAndFill();
        } catch (RuntimeException $e) {
            $this->file = null;
            $this->addError('file', $e->getMessage());

            return;
        }

        // Fresh render swaps the upload window for the review modal.
        $this->showUpload = false;
        $this->showReview = true;
        $this->analyzed = true;
        $this->ensureReviewerSelected();
    }

    /**
     * Read the uploaded workbook into the review-modal properties.
     *
     * Nothing is rejected for being blank: a barely-filled or even
     * completely empty template still opens the review modal so the
     * staff member can see exactly what was read (mostly “—” dashes) and
     * decide whether to submit it anyway.
     */
    private function analyzeAndFill(): void
    {
        $path = $this->file->getRealPath();
        $data = (new OpcrfSpreadsheet($path))->analyze();

        $this->employee_name = mb_substr(trim($data['employee_name']), 0, 255);
        $this->position = mb_substr(trim($data['position']), 0, 255);
        $this->review_period = mb_substr(trim($data['review_period']), 0, 255);
        $this->division_office = mb_substr(trim($data['division_office']), 0, 255);
        $this->objectives = trim($data['objectives']);
        $this->accomplishments = trim($data['accomplishments']);
        $this->self_rating = $data['self_rating'] !== null
            ? (string) $data['self_rating']
            : '';

        // Excel-style sheet data (title + header block + evaluator block
        // + the three parts + signer block) straight from the workbook,
        // for the template-like review modal.
        $spreadsheet = new OpcrfSpreadsheet($path);
        $this->sheet = [
            'title' => mb_substr($spreadsheet->title(), 0, 200),
            'headers' => $spreadsheet->headerCells(),
            'evaluators' => $spreadsheet->evaluatorCells(),
            'parts' => array_map(
                fn (array $part): array => [
                    'key' => $part['key'],
                    'title' => mb_substr($part['title'], 0, 200),
                    'note' => mb_substr($part['note'], 0, 600),
                    'rows' => $this->numberedRows($part['entries']),
                    'total_score' => $part['total_score'],
                    'has_total_row' => $part['has_total_row'],
                ],
                $spreadsheet->parts()
            ),
            'signers' => $spreadsheet->signers(),
        ];
    }

    /**
     * 1-based numbering for the objectives table's first column (the
     * template numbers objectives implicitly by row order).
     *
     * @param  array<int, array{row: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>  $entries
     * @return array<int, array{row: int, number: int, objectives: string, timeline: string, criteria: array<int, array{label: string, accomplishments: string, rating: ?float}>}>
     */
    private function numberedRows(array $entries): array
    {
        return array_map(function (array $entry, int $index): array {
            $entry['number'] = $index + 1;

            return $entry;
        }, array_values($entries), array_keys(array_values($entries)));
    }

    /**
     * Confirmed inside the review modal: persist the submission.
     */
    public function confirmSubmit(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        // Re-validate server-side: the review modal's data must still be
        // well-formed (the client could have crafted the call). Blanks are
        // approved — they simply record as empty.
        $validated = $this->validate();

        // Archive the staff member's actual uploaded .xlsx alongside the
        // submission, so the superadmin reviews (and can replace) the
        // literal submitted file — not just the extracted values.
        $archivedPath = null;

        if ($this->file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            $archivedPath = $this->file->storeAs(
                'opcrf-submissions/'.Auth::id(),
                uniqid().'-'.$this->file->getClientOriginalName(),
                'local'
            );
        }

        $submission = OpcrfSubmission::create([
            'user_id' => Auth::id(),
            'reviewer_id' => (int) $validated['reviewer_id'],
            'employee_name' => (string) $validated['employee_name'],
            'position' => (string) $validated['position'],
            'review_period' => (string) $validated['review_period'],
            'division_office' => (string) $validated['division_office'],
            'objectives' => (string) $validated['objectives'],
            'accomplishments' => (string) $validated['accomplishments'],
            'self_rating' => (float) ($validated['self_rating'] ?? 0),
            'remarks' => null,
            'file_path' => $archivedPath,
            'file_original_name' => $this->file?->getClientOriginalName(),
            'file_updated_at' => $archivedPath !== null ? now() : null,
            'submitted_at' => now(),
        ]);

        $this->showReview = false;
        $this->showUpload = false;
        $this->analyzed = false;
        $this->file = null;
        $this->reviewer_id = '';

        // The locked next step: right after the reviewed OPCR is recorded,
        // the "Upload your MOVs" window pops up. It stays up until at least
        // one MOV is attached — there is no way to dismiss it.
        $this->movSubmissionId = $submission->id;
        $this->movSubmissionLabel = $submission->review_period;
        $this->movsSaved = false;
        $this->movFiles = [];
        $this->showMovsModal = true;

        $this->successMessage = 'OPCR uploaded — your form was analyzed and recorded.';

        // The submissions table on the /opcrf page sits in its own component
        // — tell it the dataset changed so the confirmed upload shows up
        // without a reload. The event also broadcasts browser-wide so the
        // superadmin's Review Opcrf page and sidebar badge update live.
        $this->dispatch('opcrf-submission-created')->to(OpcrfMovs::class);
        $this->dispatch('opcrf-submission-created');
    }

    /**
     * Cancelled inside the review modal: drop everything, including the
     * temporary upload (a fresh file pick restarts the flow).
     */
    public function cancelReview(): void
    {
        $this->showReview = false;
        $this->showUpload = false;
        $this->analyzed = false;
        $this->file = null;
        $this->resetValidation();
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    /**
     * The MOVs for the locked window's submission (fresh on every render).
     *
     * @return Collection<int, OpcrfMov>
     */
    #[Computed]
    public function movsModalFiles()
    {
        $submission = $this->movSubmission();

        return $submission
            ? $submission->movs()->get()
            : collect();
    }

    /**
     * A MOV file was picked inside the locked window: validate it and move
     * it straight into permanent storage. There is no queue/attach step —
     * the window is locked, so files must land immediately. One file is
     * enough to satisfy the requirement; more can be added freely.
     */
    public function updatedMovFiles(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = $this->movSubmission();

        if ($submission === null) {
            abort(403);
        }

        $this->validate([
            'movFiles' => ['required', 'array', 'min:1', 'max:10'],
            'movFiles.*' => [
                'file',
                'max:10240',
                'extensions:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,zip',
            ],
        ], [
            'movFiles.required' => 'Choose at least one MOV file first.',
            'movFiles.max' => 'Attach up to 10 files at a time.',
            'movFiles.*.max' => 'Each file must be 10 MB or smaller.',
            'movFiles.*.extensions' => 'Allowed: PDF, Word, Excel, PowerPoint, images, and ZIP.',
        ]);

        $saved = 0;

        foreach ($this->movFiles as $file) {
            $path = $file->storeAs(
                'opcrf-movs/'.$submission->id,
                uniqid().'-'.$file->getClientOriginalName(),
                'local'
            );

            $submission->movs()->create([
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'size_bytes' => $file->getSize(),
            ]);

            $saved++;
        }

        $this->movFiles = [];
        $this->movsSaved = true;
        $this->resetValidation();
        unset($this->movsModalFiles); // recompute on the next render

        $this->successMessage = $saved === 1
            ? '1 MOV file attached to your OPCR.'
            : $saved.' MOV files attached to your OPCR.';
    }

    /**
     * Remove one attached MOV (row + stored file) from inside the locked
     * window.
     */
    public function removeMov(int $movId): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = $this->movSubmission();

        if ($submission === null) {
            abort(403);
        }

        // Scope to this submission — ids from other users' MOVs do nothing.
        $mov = $submission->movs()->whereKey($movId)->first();

        if ($mov === null) {
            return;
        }

        $name = $mov->original_name;
        $mov->delete(); // model hook removes the stored file
        unset($this->movsModalFiles);

        // The requirement is at least one MOV: if the last one went away,
        // the window locks down again until a new file is attached.
        if ($submission->movs()->count() === 0) {
            $this->movsSaved = false;
        }

        $this->successMessage = "Removed {$name}.";
    }

    /**
     * At least one MOV is attached: release the lock and close the window.
     * The window cannot be dismissed any other way — this is the only exit.
     */
    public function finishMovs(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $submission = $this->movSubmission();

        if ($submission === null) {
            abort(403);
        }

        // Still no MOVs? The lock holds — the window cannot be escaped.
        if ($submission->movs()->count() === 0) {
            $this->addError('movFiles', 'Attach at least one MOV file to continue.');

            return;
        }

        $this->resetMovs();
        $this->resetValidation();

        // Back to the dashboard: the OPCRF Template card re-renders locked,
        // since this submission now carries its MOVs.
        $this->successMessage = 'MOVs saved — your OPCR submission is complete.';
        $this->redirect(route('home'));
    }

    public function render()
    {
        return view('livewire.opcrf.upload');
    }
}
