<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\User;
use App\Notifications\OpcrfSubmitted;
use App\Support\OpcrfAccess;
use App\Support\OpcrfPartOne;
use App\Support\OpcrfSpreadsheet;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\OpcrfWorkbookProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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
 * Confirming stores a first submission or replaces the owner's current
 * submission during its update window, preserving prior workbook versions
 * and returning the latest copy to the reviewer's list.
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
     * The owner's current submission being replaced, or null for a first
     * submission. Every action reloads and verifies this ID server-side.
     */
    public ?int $submissionId = null;

    /**
     * The separate Part I upload or the complete Parts I–IV workbook.
     */
    public string $uploadMode = 'part_one';

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
     * Every tab of the analyzed workbook, in the analyzer's "workbook"
     * shape: PART II's competency sections, PART III's rating summary
     * and agreement block, PART IV's improvement plans, and any other
     * tabs (workbook-added notes sheets) as raw cell grids. Rendered
     * beneath the main PART I sheet in the review modal, and archived
     * nothing — everything here is re-read from the file at submission
     * review time.
     *
     * @var array{tabs: array<int, array{name: string, position: int, cells: int}>, part_two: array{sections: array<int, array{key: string, title: string, note: string, groups: array<int, array{label: string, indicators: array<int, array{text: string, rating: ?float}>, average: ?float}>}>, total_rows: array<int, array{key: string, label: string, score: ?float}>, signers: array<int, array{ref: string, role: string, name: string}>}, part_three: array{components: array<int, array{part: string, component: string, weight: string, obtained: ?float}>, agreement: array<int, array{role: string, name: string}>}, part_four: array{office_plan: array<int, array<string, string>>, office_feedback: string, development_plan: array<int, array<string, string>>, development_feedback: string, signers: array<int, array{ref: string, role: string, name: string}>}, extra_sheets: array<int, array{name: string, rows: array<int, array{row: int, cells: array<int, array{ref: string, column: string, value: string}>}}>}
     */
    public array $workbook = ['tabs' => [], 'part_two' => ['sections' => [], 'total_rows' => [], 'signers' => []], 'part_three' => ['components' => [], 'agreement' => []], 'part_four' => ['office_plan' => [], 'office_feedback' => '', 'development_plan' => [], 'development_feedback' => '', 'signers' => []], 'extra_sheets' => []];

    /**
     * Success toast after a confirmed upload (clearSuccess pattern).
     */
    public ?string $successMessage = null;

    /**
     * The name read out of the uploaded workbook, shown in the review modal
     * so the staff member can see exactly which name the system read.
     */
    public string $verifiedName = '';

    /**
     * The upload check's verdict, shown in the review modal. Null when the
     * workbook says nothing worth remarking on; otherwise a plain-language
     * heads-up ("this file names somebody else", "no name could be read",
     * "this was downloaded from another account") that the staff member is
     * free to ignore — the upload proceeds either way, and the note rides
     * along to the reviewer.
     */
    public ?string $nameNote = null;

    /**
     * The template version stamped into the uploaded workbook (null when the
     * file carries no stamp — an older copy, or one made by hand).
     */
    public ?string $templateVersion = null;

    /**
     * The superadmin every submission is routed to — resolved
     * automatically at submit time (config('opcrf.review_route'), by
     * username, falling back to the first superadmin by username), never
     * picked by the staff member.
     */
    public $reviewer_id = '';

    /**
     * Rules for the review modal's confirm step. Blanks are approved:
     * whatever the spreadsheet did or didn't contain gets recorded, with
     * empty strings for unfilled text and self_rating stored as 0.00
     * when the template carried no ratings (shown as “—” in the table).
     *
     * The recipient is never client-supplied — resolveAutoReviewer()
     * chooses it server-side at submit time.
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
        ];
    }

    public function mount(): void
    {
        // Everything this component records is attributed to a user, and
        // confirmSubmit() reads Auth::id() — so a guest is refused here
        // rather than half way through a submit.
        abort_unless(Auth::user()?->canAccessOpcrfTemplate(), 404);
        abort_unless(Auth::check(), 404);
        abort_if(!Auth::user()->canAccessStaffPages(), 404);
    }

    /**
     * Clicked the template card's trigger: pop up the upload window.
     */
    public function openUpload(): void
    {
        abort_unless(Auth::user()?->canAccessOpcrfTemplate(), 404);
        abort_unless(Auth::check(), 404);
        abort_if(!Auth::user()->canAccessStaffPages(), 404);

        $this->uploadMode = 'part_one';
        $submission = $this->latestSubmission(OpcrfSubmission::PART_ONE);
        abort_unless($this->canUploadSubmission($submission), 403);

        $this->submissionId = $submission?->id;
        $this->resetValidation();
        $this->showUpload = true;
    }

    public function openFullUpload(): void
    {
        abort_unless(Auth::user()?->canAccessOpcrfTemplate(), 404);
        abort_unless(Auth::check(), 404);
        abort_if(!Auth::user()->canAccessStaffPages(), 404);

        $this->uploadMode = 'full_workbook';
        $submission = $this->latestSubmission(OpcrfSubmission::FULL_WORKBOOK);
        abort_unless($this->canUploadSubmission($submission), 403);

        $this->submissionId = $submission?->id;
        $this->resetValidation();
        $this->showUpload = true;
    }

    public function canUploadNow(): bool
    {
        if (!Auth::user()?->canAccessOpcrfTemplate()) {
            return false;
        }

        return $this->canUploadPartOneSubmission(
            $this->latestSubmission(OpcrfSubmission::PART_ONE)
        );
    }

    public function canUploadFullNow(): bool
    {
        if (!Auth::user()?->canAccessOpcrfTemplate()) {
            return false;
        }

        return $this->canUploadFullSubmission(
            $this->latestSubmission(OpcrfSubmission::FULL_WORKBOOK)
        );
    }

    public function fullUploadWaitingForPartOneApproval(): bool
    {
        return Auth::user()?->canAccessOpcrfTemplate() === true
            && OpcrfPartOne::fullTemplateAvailable()
            && $this->latestApprovedPartOne() === null;
    }

    public function isUpdatingSubmission(): bool
    {
        return $this->submissionId !== null || $this->latestSubmission(
            $this->isFullWorkbookUpload()
            ? OpcrfSubmission::FULL_WORKBOOK
            : OpcrfSubmission::PART_ONE
        ) !== null;
    }

    public function isFullWorkbookUpload(): bool
    {
        return $this->uploadMode === 'full_workbook';
    }

    public function isUpdatingFullWorkbook(): bool
    {
        return ($this->isFullWorkbookUpload() && $this->submissionId !== null)
            || $this->latestSubmission(OpcrfSubmission::FULL_WORKBOOK) !== null;
    }

    public function isUpdatingPartOneSubmission(): bool
    {
        return (!$this->isFullWorkbookUpload() && $this->submissionId !== null)
            || $this->latestSubmission(OpcrfSubmission::PART_ONE) !== null;
    }

    private function latestSubmission(int $part): ?OpcrfSubmission
    {
        return OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->where('opcrf_part', $part)
            ->latest('id')
            ->first();
    }

    private function canUploadSubmission(?OpcrfSubmission $submission): bool
    {
        if ($this->isFullWorkbookUpload()) {
            return $this->canUploadFullSubmission($submission);
        }

        return $this->canUploadPartOneSubmission($submission);
    }

    private function canUploadPartOneSubmission(?OpcrfSubmission $submission): bool
    {
        if ($submission?->isLockedForStaffUpdate()) {
            return false;
        }

        if ($submission?->status === OpcrfSubmission::STATUS_RETURNED) {
            return true;
        }

        return Auth::check()
            && OpcrfAccess::partState(Auth::user(), OpcrfSubmission::PART_ONE)['open'];
    }

    private function canUploadFullSubmission(?OpcrfSubmission $submission): bool
    {
        if (
            !Auth::user()?->canAccessOpcrfTemplate()
            || !OpcrfPartOne::fullTemplateAvailable()
            || $this->latestApprovedPartOne() === null
        ) {
            return false;
        }

        return $submission === null
            || $submission->status === OpcrfSubmission::STATUS_RETURNED;
    }

    private function latestApprovedPartOne(): ?OpcrfSubmission
    {
        return OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->where('opcrf_part', OpcrfSubmission::PART_ONE)
            ->approved()
            ->latest('id')
            ->first();
    }

    /**
     * Recheck the schedule and submission ownership on every upload action.
     * A stale first-submission modal cannot create a duplicate if another
     * submission was filed in another tab.
     */
    private function authorizedSubmissionForUpload(): ?OpcrfSubmission
    {
        abort_unless(Auth::user()?->canAccessOpcrfTemplate(), 404);
        abort_unless(Auth::check(), 404);
        abort_if(!Auth::user()->canAccessStaffPages(), 404);

        $part = $this->isFullWorkbookUpload()
            ? OpcrfSubmission::FULL_WORKBOOK
            : OpcrfSubmission::PART_ONE;
        $latest = $this->latestSubmission($part);

        if ($this->submissionId !== null) {
            $submission = OpcrfSubmission::query()
                ->where('user_id', Auth::id())
                ->findOrFail($this->submissionId);
            abort_unless($latest?->is($submission), 404);
        } else {
            $submission = $latest;
            abort_if($submission !== null, 409);
        }

        abort_unless($this->canUploadSubmission($submission), 403);

        return $submission;
    }

    /**
     * The superadmin submissions route to automatically: the configured
     * account (by username), or — when no account carries that name — the
     * first superadmin by username, so a submission is never stranded
     * unroutable. Null when the system has no superadmin at all (the
     * submit then fails validation with an explanation).
     */
    private function resolveAutoReviewer(): ?User
    {
        $configured = trim((string) config('opcrf.review_route', ''));

        $reviewer = null;

        if ($configured !== '') {
            $reviewer = User::query()
                ->where(function ($query): void {
                    $query->where('is_superadmin', true)->orWhere('role', 'administrator');
                })
                ->where('username', $configured)
                ->first();
        }

        return $reviewer
            ?? User::query()
                ->where(function ($query): void {
                    $query->where('is_superadmin', true)->orWhere('role', 'administrator');
                })
                ->orderBy('username')
                ->first();
    }

    /**
     * Dismissed the upload window without picking a file: drop anything
     * temporary so the next open starts clean.
     */
    public function closeUpload(): void
    {
        $this->showUpload = false;
        $this->file = null;
        $this->submissionId = null;
        $this->uploadMode = 'part_one';
        $this->verifiedName = '';
        $this->nameNote = null;
        $this->templateVersion = null;
        $this->resetValidation();
    }

    /**
     * Uploaded → analyze the workbook → close the upload window and open
     * the review modal over the dashboard.
     */
    public function updatedFile(): void
    {
        $this->authorizedSubmissionForUpload();

        $this->validate([
            'file' => ['required', 'file', 'max:' . (int) config('opcrf.template.max_kb', 10240), 'extensions:xlsx'],
        ], [
            'file.required' => 'Please upload your completed OPCRF template before submitting.',
            'file.extensions' => 'The OPCRF must be the .xlsx template file.',
            'file.max' => 'The file is too large (10 MB maximum).',
        ]);

        // What can the system tell about this workbook? Read its own contents —
        // the template stamp and the name typed into its header block — so
        // the reviewer can be told what it says.
        //
        // Nothing about that can refuse the upload. A name that is absent,
        // spelled differently, or somebody else's is recorded, not enforced:
        // staff file their OPCR freely. Whatever is found becomes $nameNote,
        // shown in the review modal and saved on the submission.
        $check = OpcrfTemplatePersonalizer::verify($this->file->getRealPath(), Auth::user());

        // Kept for the review modal and for the submission's template link.
        $this->templateVersion = $check['version'];
        $this->verifiedName = $check['name'];
        $this->nameNote = $check['status'] === OpcrfTemplatePersonalizer::MATCH
            ? null
            : $check['message'];

        $analysisPath = $this->file->getRealPath();
        $temporaryAnalysisPath = null;

        try {
            if ($this->isFullWorkbookUpload()) {
                $analysisBytes = $this->synchronizedFullWorkbookBytes($analysisPath);
                $temporaryAnalysisPath = tempnam(sys_get_temp_dir(), 'opcrf-full-review-');

                if (
                    $temporaryAnalysisPath === false
                    || file_put_contents($temporaryAnalysisPath, $analysisBytes) === false
                ) {
                    throw new RuntimeException('The synchronized full OPCRF could not be prepared for review.');
                }

                $analysisPath = $temporaryAnalysisPath;
            }

            $this->analyzeAndFill($analysisPath);
        } catch (RuntimeException $e) {
            $this->file = null;
            $this->addError('file', $e->getMessage());

            return;
        } finally {
            if ($temporaryAnalysisPath !== null && is_file($temporaryAnalysisPath)) {
                unlink($temporaryAnalysisPath);
            }
        }

        // Fresh render swaps the upload window for the review modal.
        $this->showUpload = false;
        $this->showReview = true;
        $this->analyzed = true;
    }

    /**
     * Read the uploaded workbook into the review-modal properties.
     *
     * Nothing is rejected for being blank: a barely-filled or even
     * completely empty template still opens the review modal so the
     * staff member can see exactly what was read (mostly “—” dashes) and
     * decide whether to submit it anyway.
     */
    private function analyzeAndFill(string $path): void
    {
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

        // Every tab of the file: PART II / III / IV parsed into their
        // layouts, plus any non-standard tabs as raw cell grids.
        $this->workbook = $spreadsheet->workbook();

        $this->sheet = [
            'title' => mb_substr($spreadsheet->title(), 0, 200),
            'headers' => $spreadsheet->headerCells(),
            'evaluators' => $spreadsheet->evaluatorCells(),
            'parts' => array_map(
                fn(array $part): array => [
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
     * Ensure a Parts I–IV upload really contains all four official worksheets,
     * then replace its Part I values with the separately approved submission.
     */
    private function synchronizedFullWorkbookBytes(string $uploadedPath): string
    {
        $workbook = new OpcrfSpreadsheet($uploadedPath);
        $sheetNames = array_map(
            static fn(array $sheet): string => mb_strtoupper(trim((string) ($sheet['name'] ?? ''))),
            $workbook->sheetNames()
        );

        foreach (['PART I', 'PART II', 'PART III', 'PART IV'] as $requiredSheet) {
            if (
                !collect($sheetNames)->contains(
                    static fn(string $name): bool => str_starts_with($name, $requiredSheet)
                )
            ) {
                throw new RuntimeException(
                    'Upload the complete OPCRF workbook with its Part I, II, III, and IV sheets.'
                );
            }
        }

        $approvedPartOne = $this->latestApprovedPartOne();

        if ($approvedPartOne === null) {
            throw new RuntimeException('Your Part I OPCRF must be approved before you can submit the full workbook.');
        }

        $bytes = file_get_contents($uploadedPath);

        if ($bytes === false) {
            throw new RuntimeException('The uploaded full OPCRF workbook could not be read.');
        }

        $sourcePath = $approvedPartOne->downloadPath();

        if ($sourcePath !== null && Storage::disk('local')->exists($sourcePath)) {
            return OpcrfWorkbookProgress::mergePartOne(
                $bytes,
                Storage::disk('local')->path($sourcePath),
                replaceEmptyCells: true
            );
        }

        return OpcrfWorkbookProgress::mergeSubmissionData(
            $bytes,
            [
                'employee_name' => $approvedPartOne->employee_name,
                'position' => $approvedPartOne->position,
                'review_period' => $approvedPartOne->review_period,
                'division_office' => $approvedPartOne->division_office,
                'objectives' => $approvedPartOne->objectives,
                'accomplishments' => $approvedPartOne->accomplishments,
                'self_rating' => $approvedPartOne->self_rating,
            ],
            true
        );
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
        $existingSubmission = $this->authorizedSubmissionForUpload();

        $this->validate([
            'file' => ['required', 'file', 'max:' . (int) config('opcrf.template.max_kb', 10240), 'extensions:xlsx'],
        ], [
            'file.required' => 'Please upload your completed OPCRF template before submitting.',
            'file.extensions' => 'The OPCRF must be the .xlsx template file.',
            'file.max' => 'The file is too large (10 MB maximum).',
        ]);

        // Re-validate server-side: the review modal's data must still be
        // well-formed (the client could have crafted the call). Blanks are
        // approved — they simply record as empty.
        $validated = $this->validate();

        $uploadCheck = null;
        $accountName = null;
        if ($this->file instanceof TemporaryUploadedFile) {
            $check = OpcrfTemplatePersonalizer::verify($this->file->getRealPath(), Auth::user());
            $uploadCheck = $check['status'];
            $accountName = $check['expected'];
        }

        if ($this->isFullWorkbookUpload()) {
            $this->confirmFullWorkbookSubmit(
                $existingSubmission,
                $uploadCheck,
                $accountName
            );

            return;
        }

        if ($existingSubmission !== null) {
            $archivedPath = $this->file->storeAs(
                'opcrf-submissions/' . Auth::id(),
                uniqid() . '-' . $this->file->getClientOriginalName(),
                'local'
            );

            $existingSubmission->replaceDuringDeadline(
                $archivedPath,
                $this->file->getClientOriginalName(),
                [
                    'opcrf_template_id' => OpcrfTemplate::latestFor(Auth::user())?->id,
                    'employee_name' => (string) $validated['employee_name'],
                    'upload_check' => $uploadCheck,
                    'account_name' => $accountName,
                    'position' => (string) $validated['position'],
                    'review_period' => (string) $validated['review_period'],
                    'division_office' => (string) $validated['division_office'],
                    'objectives' => (string) $validated['objectives'],
                    'accomplishments' => (string) $validated['accomplishments'],
                    'self_rating' => (float) ($validated['self_rating'] ?? 0),
                ]
            );
            $existingSubmission->stampSignature();

            $reviewer = $existingSubmission->assignedTo ?? $existingSubmission->reviewer;
            $reviewer?->notify(new OpcrfSubmitted($existingSubmission));

            $this->finishUpload('OPCR updated — the revised form was recorded and sent for review.');

            return;
        }

        // The recipient is chosen by the system, not the staff member:
        // the configured superadmin (Eve by default), first superadmin as
        // the fallback.
        $reviewer = $this->resolveAutoReviewer();

        if ($reviewer === null) {
            $this->addError('file', 'No superadmin account exists yet — ask an administrator to create one before you can submit your OPCR.');

            return;
        }

        $archivedPath = $this->file->storeAs(
            'opcrf-submissions/' . Auth::id(),
            uniqid() . '-' . $this->file->getClientOriginalName(),
            'local'
        );

        // Re-read server-side before anything is written: the contents were
        // read when the file was picked, but the client could have crafted
        // this call. Same rule as the upload window — this records what the
        // workbook says, and nothing more.
        // Archive the staff member's actual uploaded .xlsx alongside the
        // submission, so the superadmin reviews (and can replace) the
        // literal submitted file — not just the extracted values. The bytes
        // are stored exactly as received: nothing overwrites or rewrites
        // them, so the archived workbook is the staff member's own file.
        // The template the account last generated, recorded when there is
        // one: a submission is judged on its contents, so an account that
        // never downloaded a template is not blocked — the link is simply
        // left empty rather than invented.
        $template = OpcrfTemplate::latestFor(Auth::user());

        $submission = OpcrfSubmission::create([
            'user_id' => Auth::id(),
            // Which Part of the form this answers. The dashboard's upload
            // card belongs to Part 1 — the part the personalized template is
            // generated from — so that is what it records. Part 2-4
            // submissions are filed from their own guarded Part pages.
            'opcrf_part' => OpcrfSubmission::PART_ONE,
            'opcrf_template_id' => $template?->id,
            'reviewer_id' => $reviewer->id,
            'employee_name' => (string) $validated['employee_name'],
            // What the upload check said, and the account name it said it
            // against — so the reviewer can see the comparison without
            // opening the workbook, and without the account being renamed
            // afterwards rewriting history.
            'upload_check' => $uploadCheck,
            'account_name' => $accountName,
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

        // The account's digital signature is encoded into the workbook right
        // here, at the moment of filing: the staff member signs the OPCR by
        // having a signature on their profile, so the reviewer's copy of the
        // file carries it at the bottom of every part with a signer block. It
        // carries no authority to refuse anything — a submission with no
        // signature on file (or a workbook that cannot be stamped) is archived
        // exactly as uploaded.
        $submission->stampSignature();

        // The reviewer now has work waiting: their notification bell in the
        // topbar says so without them having to open Review Opcrf.
        $reviewer->notify(new OpcrfSubmitted($submission));

        $this->finishUpload('OPCR uploaded — your form was analyzed and recorded.');
    }

    private function confirmFullWorkbookSubmit(
        ?OpcrfSubmission $existingSubmission,
        ?string $uploadCheck,
        ?string $accountName
    ): void {
        $approvedPartOne = $this->latestApprovedPartOne();

        if ($approvedPartOne === null) {
            abort(403);
        }

        try {
            $workbookBytes = $this->synchronizedFullWorkbookBytes($this->file->getRealPath());
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $originalName = $this->file->getClientOriginalName();
        $archivedPath = 'opcrf-submissions/' . Auth::id() . '/' . uniqid() . '-' . $originalName;

        if (!Storage::disk('local')->put($archivedPath, $workbookBytes)) {
            $this->addError('file', 'The full OPCRF workbook could not be saved. Please try again.');

            return;
        }

        $template = OpcrfTemplate::latestFor(Auth::user());
        $partOneData = [
            'opcrf_template_id' => $template?->id,
            'employee_name' => (string) $approvedPartOne->employee_name,
            'upload_check' => $uploadCheck,
            'account_name' => $accountName,
            'position' => (string) $approvedPartOne->position,
            'review_period' => (string) $approvedPartOne->review_period,
            'division_office' => (string) $approvedPartOne->division_office,
            'objectives' => (string) $approvedPartOne->objectives,
            'accomplishments' => (string) $approvedPartOne->accomplishments,
            'self_rating' => (float) ($approvedPartOne->self_rating ?? 0),
        ];

        if ($existingSubmission !== null) {
            $existingSubmission->resubmit($archivedPath, $originalName);
            $existingSubmission->fill($partOneData)->save();
            $submission = $existingSubmission;
            $reviewer = $submission->assignedTo ?? $submission->reviewer;
        } else {
            $reviewer = $this->resolveAutoReviewer();

            if ($reviewer === null) {
                Storage::disk('local')->delete($archivedPath);
                $this->addError(
                    'file',
                    'No superadmin account exists yet — ask an administrator to create one before you can submit your OPCRF.'
                );

                return;
            }

            $submission = OpcrfSubmission::create([
                'user_id' => Auth::id(),
                'opcrf_part' => OpcrfSubmission::FULL_WORKBOOK,
                'reviewer_id' => $reviewer->id,
                ...$partOneData,
                'remarks' => null,
                'file_path' => $archivedPath,
                'file_original_name' => $originalName,
                'file_updated_at' => now(),
                'submitted_at' => now(),
            ]);
        }

        $submission->stampSignature();
        $reviewer?->notify(new OpcrfSubmitted($submission));

        $this->finishUpload('Full OPCRF submitted — the approved Part I was kept in sync and the complete workbook was sent for review.');
    }

    private function finishUpload(string $successMessage): void
    {
        $this->showReview = false;
        $this->showUpload = false;
        $this->analyzed = false;
        $this->file = null;
        $this->submissionId = null;
        $this->uploadMode = 'part_one';
        $this->reviewer_id = '';
        $this->nameNote = null;
        $this->verifiedName = '';
        $this->successMessage = $successMessage;

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
        $this->submissionId = null;
        $this->uploadMode = 'part_one';
        $this->verifiedName = '';
        $this->nameNote = null;
        $this->templateVersion = null;
        $this->resetValidation();

        $this->workbook = ['tabs' => [], 'part_two' => ['sections' => [], 'total_rows' => [], 'signers' => []], 'part_three' => ['components' => [], 'agreement' => []], 'part_four' => ['office_plan' => [], 'office_feedback' => '', 'development_plan' => [], 'development_feedback' => '', 'signers' => []], 'extra_sheets' => []];
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.opcrf.upload');
    }
}
