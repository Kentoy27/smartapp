<?php

namespace App\Livewire;

use App\Models\AipPlan;
use App\Models\User;
use App\Models\WfpPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The /aip landing page: the school's Annual Implementation Plans.
 *
 * One plan exists per school per year, so this is a list of years — the
 * current draft/submission and every earlier approved plan — plus the form
 * that starts a new year's plan. Opening a plan is a navigation to the
 * editor, which is where the working happens.
 *
 * Only the School Head of a school may reach this page (canAccessAip()); the
 * component re-checks the gate on every action, because hiding the menu item
 * is not the boundary.
 */
#[Title('Annual Implementation Plan — SmartApp')]
class AipManager extends Component
{
    /** The year the "start a new plan" form will use. */
    public int $year = 0;

    /** An optional title for the new plan (defaults to the year's plan). */
    public string $title = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()?->canAccessAip(), 404);

        $this->year = (int) now()->format('Y');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->canAccessAip(), 404);

        return $user;
    }

    /**
     * The school's plans, newest year first.
     *
     * @return Collection<int, AipPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        $user = $this->user();

        if ($user->school_id === null) {
            return collect();
        }

        return AipPlan::query()
            ->where('school_id', $user->school_id)
            ->withCount('projects', 'activities')
            ->with(['approvedBy', 'wfpPlan'])
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get();
    }

    /** Whether the signed-in account is attached to a school at all. */
    #[Computed]
    public function hasSchool(): bool
    {
        return $this->user()->school_id !== null;
    }

    /**
     * The month of the year that is not yet covered by a plan, if any — used
     * to point the reader at the plan they most likely want to open.
     */
    #[Computed]
    public function currentPlan(): ?AipPlan
    {
        return $this->plans->firstWhere('year', (int) now()->format('Y'));
    }

    /**
     * Start (or open) the plan for the chosen year. Idempotent by design:
     * one plan per school per year, so asking twice opens the same plan
     * rather than creating a duplicate.
     */
    public function create(): void
    {
        $user = $this->user();

        if ($user->school_id === null) {
            $this->errorMessage = 'Your account is not attached to a school yet, so it cannot own an AIP. Ask an administrator to assign your school.';

            return;
        }

        $this->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'title' => ['nullable', 'string', 'max:255'],
        ], [
            'year.required' => 'Choose the school year the plan covers.',
            'year.min' => 'Enter a valid four-digit school year.',
            'year.max' => 'Enter a valid four-digit school year.',
            'title.max' => 'Keep the title under 255 characters.',
        ], [
            'year' => 'school year',
            'title' => 'title',
        ]);

        $plan = DB::transaction(function () use ($user): AipPlan {
            $plan = AipPlan::firstOrCreate(
                ['school_id' => $user->school_id, 'year' => $this->year],
                [
                    'title' => trim($this->title) !== '' ? trim($this->title) : null,
                    'status' => AipPlan::STATUS_DRAFT,
                    'created_by' => $user->id,
                ],
            );

            // Connect the plan to the school's WFP for the same year, when one
            // exists — the AIP is financed by the WFP, so the link is recorded
            // rather than retyped.
            if ($plan->wfp_plan_id === null) {
                $plan->wfp_plan_id = WfpPlan::query()
                    ->where('school_id', $user->school_id)
                    ->where('year', $this->year)
                    ->value('id');

                $plan->save();
            }

            return $plan;
        });

        $this->redirect(route('aip.plans.edit', ['plan' => $plan->id]), navigate: true);
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function render()
    {
        return view('livewire.aip.manager');
    }
}
