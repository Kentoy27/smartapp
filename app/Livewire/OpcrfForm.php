<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use App\Support\OpcrfAccess;
use App\Support\OpcrfTemplatePersonalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * OPCRF submission form for staff accounts.
 *
 * The user fills in the same details the downloadable template asks for
 * (employee info, objectives, accomplishments, self-rating) and hits
 * Submit. Before anything is stored, a REVIEW step pops up within the
 * page showing every entered detail so it can be checked and either
 * confirmed (saved) or cancelled (back to the form, still editable).
 */
#[Title('Opcrf — SmartApp')]
class OpcrfForm extends Component
{
    /**
     * Review-before-submit gate. When true the template-like review
     * modal is rendered on top of the form; nothing is persisted until
     * the user confirms inside it.
     */
    public bool $showReview = false;

    public ?int $submissionId = null;

    /**
     * Set after a successful submit so the view can pop a SweetAlert
     * toast and swap the form for a confirmation card (same pattern as
     * UsersTable's $successMessage).
     */
    public ?string $successMessage = null;

    // ----- Form fields, mirroring the OPCRF template header + body -----

    public string $employee_name = '';

    public string $position = '';

    public string $review_period = '';

    public string $division_office = '';

    public string $objectives = '';

    public string $accomplishments = '';

    public string $self_rating = '';

    /**
     * Validation rules. Rating is a plain numeric string here because the
     * wire:model input yields strings; the DB column handles the decimals.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'employee_name' => ['required', 'string', 'max:255'],
            'position' => ['required', 'string', 'max:255'],
            'review_period' => ['required', 'string', 'max:255'],
            'division_office' => ['required', 'string', 'max:255'],
            'objectives' => ['required', 'string'],
            'accomplishments' => ['required', 'string'],
            'self_rating' => ['required', 'numeric', 'min:1', 'max:5'],
        ];
    }

    /**
     * Human-friendly field names for error messages.
     *
     * @return array<string, string>
     */
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
        // The form is pre-filled from the signed-in account, so it needs one.
        // Without this, identityFor(null) below raises a TypeError — a 500
        // rather than a refusal.
        abort_unless(Auth::check(), 404);

        // Staff-only page; superadmins use their own tooling (same guard
        // as the /opcrf route itself).
        abort_if(! Auth::user()->canAccessStaffPages(), 404);

        // Pre-filled from the signed-in account — and from the SAME source
        // the personalized template download writes from
        // (OpcrfTemplatePersonalizer::identityFor), so the typed form and the
        // downloaded workbook can never disagree about who the staff member
        // is. One user-information source, not two.
        $identity = OpcrfTemplatePersonalizer::identityFor(Auth::user());

        $submission = $this->latestPartOneSubmission();
        $this->submissionId = $submission?->id;
        $this->employee_name = $submission?->employee_name ?: $identity['name'];
        $this->position = $submission?->position ?: $identity['position'];
        $this->review_period = $submission?->review_period ?: $identity['review_period'];
        $this->division_office = $submission?->division_office ?: $identity['division_office'];
        $this->objectives = $submission?->objectives ?? '';
        $this->accomplishments = $submission?->accomplishments ?? '';
        $this->self_rating = $submission !== null && $submission->self_rating > 0
            ? (string) $submission->self_rating
            : '';
    }

    /**
     * Validate everything, then open the REVIEW modal instead of saving.
     * The modal re-displays every entered detail inside the page so the
     * user can confirm before the submission is stored.
     */
    public function submitForReview(): void
    {
        abort_unless(Auth::check(), 404);
        abort_if(! Auth::user()?->canAccessStaffPages(), 404);
        abort_unless($this->partOneIsOpen(), 403);

        $this->validate();

        // Fresh render shows the filled review modal over the form.
        $this->showReview = true;
    }

    /**
     * Confirmed inside the review modal: persist the submission.
     */
    public function confirmSubmit(): void
    {
        abort_unless(Auth::check(), 404);
        abort_if(! Auth::user()?->canAccessStaffPages(), 404);
        abort_unless($this->partOneIsOpen(), 403);

        // Re-validate: the client could have changed fields between the
        // review gate opening and confirming (or crafted the call).
        $validated = $this->validate();

        $attributes = [
            'employee_name' => $validated['employee_name'],
            'position' => $validated['position'],
            'review_period' => $validated['review_period'],
            'division_office' => $validated['division_office'],
            'objectives' => $validated['objectives'],
            'accomplishments' => $validated['accomplishments'],
            'self_rating' => (float) $validated['self_rating'],
        ];

        $submission = DB::transaction(function () use ($attributes): OpcrfSubmission {
            $query = OpcrfSubmission::query()
                ->where('user_id', Auth::id())
                ->where('opcrf_part', OpcrfSubmission::PART_ONE)
                ->latest('id')
                ->lockForUpdate();

            $submission = $query->first();

            if ($submission !== null) {
                abort_unless($submission->user_id === Auth::id(), 404);
                abort_unless(
                    $this->submissionId === null || $submission->id === $this->submissionId,
                    409
                );
                abort_unless(! $submission->isLockedForStaffUpdate(), 403);
                $submission->updateFormDuringDeadline($attributes);

                return $submission;
            }

            abort_if($this->submissionId !== null, 404);

            return OpcrfSubmission::create([
                ...$attributes,
                'user_id' => Auth::id(),
                'opcrf_part' => OpcrfSubmission::PART_ONE,
                'submitted_at' => now(),
            ]);
        });

        $this->showReview = false;
        $this->submissionId = $submission->id;
        $this->successMessage = 'OPCRF '
            .($submission->wasRecentlyCreated ? 'submitted' : 'updated')
            .' — your form was recorded on '
            .$submission->submitted_at->format('M j, Y g:i A').'.';
    }

    private function latestPartOneSubmission(): ?OpcrfSubmission
    {
        return OpcrfSubmission::query()
            ->where('user_id', Auth::id())
            ->where('opcrf_part', OpcrfSubmission::PART_ONE)
            ->latest('id')
            ->first();
    }

    private function partOneIsOpen(): bool
    {
        if ($this->latestPartOneSubmission()?->isLockedForStaffUpdate()) {
            return false;
        }

        return OpcrfAccess::partState(
            Auth::user(),
            OpcrfSubmission::PART_ONE
        )['open'];
    }

    public function canEdit(): bool
    {
        return Auth::check()
            && ! Auth::user()->hasAdminAccess()
            && $this->partOneIsOpen();
    }

    /**
     * Cancelled inside the review modal: close it and return to the
     * still-filled form (values are Livewire-persisted, nothing is lost).
     */
    public function cancelReview(): void
    {
        $this->showReview = false;
        $this->resetValidation();
    }

    /**
     * Clear the fields for another edit without detaching this form from its
     * existing submission row.
     */
    public function startNew(): void
    {
        $this->reset(
            'employee_name', 'position', 'review_period', 'division_office',
            'objectives', 'accomplishments', 'self_rating',
        );
        // Accounts created in Users no longer carry a real name (it was never used),
        // so fall back to the login — the OPCRF still needs an employee name.
        $this->employee_name = (string) (Auth::user()->name ?: Auth::user()->username);
        $this->resetValidation();
        $this->successMessage = null;
    }

    /**
     * Called by the view right after the SweetAlert has been popped, so
     * the message doesn't re-fire on subsequent renders (UsersTable
     * pattern).
     */
    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.opcrf.form');
    }
}
