<?php

namespace App\Livewire;

use App\Models\OpcrfSubmission;
use Illuminate\Support\Facades\Auth;
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

    public string $remarks = '';

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
            'remarks' => ['nullable', 'string', 'max:2000'],
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
            'remarks' => 'remarks',
        ];
    }

    public function mount(): void
    {
        // Staff-only page; superadmins use their own tooling (same guard
        // as the /opcrf route itself).
        abort_if(Auth::user()?->is_superadmin, 404);

        // Pre-fill from the signed-in account — matches what the template
        // header would normally carry.
        $user = Auth::user();

        $this->employee_name = (string) ($user->name ?? '');
        $this->position = '';
        $this->review_period = '';
        $this->division_office = '';
        $this->objectives = '';
        $this->accomplishments = '';
        $this->self_rating = '';
        $this->remarks = '';
    }

    /**
     * Validate everything, then open the REVIEW modal instead of saving.
     * The modal re-displays every entered detail inside the page so the
     * user can confirm before the submission is stored.
     */
    public function submitForReview(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        $this->validate();

        // Fresh render shows the filled review modal over the form.
        $this->showReview = true;
    }

    /**
     * Confirmed inside the review modal: persist the submission.
     */
    public function confirmSubmit(): void
    {
        abort_if(Auth::user()?->is_superadmin, 404);

        // Re-validate: the client could have changed fields between the
        // review gate opening and confirming (or crafted the call).
        $validated = $this->validate();

        $submission = OpcrfSubmission::create([
            'user_id' => Auth::id(),
            'employee_name' => $validated['employee_name'],
            'position' => $validated['position'],
            'review_period' => $validated['review_period'],
            'division_office' => $validated['division_office'],
            'objectives' => $validated['objectives'],
            'accomplishments' => $validated['accomplishments'],
            'self_rating' => (float) $validated['self_rating'],
            'remarks' => $validated['remarks'] ?: null,
            'submitted_at' => now(),
        ]);

        $this->showReview = false;
        $this->successMessage = 'OPCRF submitted — your form was recorded on '
            .$submission->submitted_at->format('M j, Y g:i A').'.';
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
     * Start a brand-new blank form after a successful submission.
     */
    public function startNew(): void
    {
        $this->reset(
            'employee_name', 'position', 'review_period', 'division_office',
            'objectives', 'accomplishments', 'self_rating', 'remarks',
        );
        $this->employee_name = (string) (Auth::user()->name ?? '');
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
