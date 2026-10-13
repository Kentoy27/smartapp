<?php

namespace App\Livewire;

use App\Models\AipActivity;
use App\Models\AipPlan;
use App\Models\AipProject;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\User;
use App\Models\UserMov;
use App\Support\AipXlsxExporter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The AIP editor: the school's plan as a spreadsheet.
 *
 * The contents are two levels — School Improvement Projects, each with a
 * grid of activities — because that is how the official form (Annex 10,
 * DepEd Order No. 44, s. 2015) is laid out: a project's title/objective/
 * output for the year head its block, and its activities follow with
 * person(s) responsible, schedule/venue, budget per activity and budget
 * source.
 *
 * The grid lives in `public array $projects`; every row carries a stable
 * `_key` so re-rendering never loses a cell. Wildcard rules are validated
 * only in the mutating actions, and the budget totals are recomputed on
 * every render (a #[Computed] `totals()`), so the grid always shows the
 * truth even before a save. The PERSISTED contents are reconciled
 * server-side on save, never trusted from the browser.
 *
 * Saving is guarded by an optimistic lock: `loadedVersion` is compared to the
 * plan's current version, and a save is refused with a "changed elsewhere"
 * message when they differ. Submitting archives an immutable version
 * snapshot, so no submitted copy is ever overwritten.
 */
#[Title('Annual Implementation Plan — SmartApp')]
class AipEditor extends Component
{
    #[Locked]
    public int $planId = 0;

    #[Locked]
    public int $loadedVersion = 0;

    /** The plan's title (the header line of the printed form). */
    public string $title = '';

    /** @var array<int, array<string, mixed>> */
    public array $projects = [];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /** Whether the preview modal is open. */
    public bool $showPreview = false;

    public function mount(?AipPlan $plan = null): void
    {
        abort_unless($plan !== null && $plan->managedBy(Auth::user()), 404);

        $this->planId = $plan->id;
        $this->loadedVersion = (int) $plan->version;
        $this->title = (string) ($plan->title ?? '');
        $this->loadProjects($plan);
    }

    /* -------------------------------------------------------------- guards */

    private function guard(): AipPlan
    {
        $plan = AipPlan::findOrFail($this->planId);
        abort_unless($plan->managedBy(Auth::user()), 404);

        return $plan;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 404);

        return $user;
    }

    /* ---------------------------------------------------------- load/blank */

    private function loadProjects(AipPlan $plan): void
    {
        $this->projects = $plan->projects()
            ->with('activities')
            ->get()
            ->map(fn (AipProject $project): array => [
                '_key' => 'project-'.$project->id,
                'id' => $project->id,
                'title' => (string) $project->title,
                'objective' => (string) ($project->objective ?? ''),
                'output_for_year' => (string) ($project->output_for_year ?? ''),
                'activities' => $project->activities
                    ->map(fn (AipActivity $activity): array => self::activityFromModel($activity))
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityFromModel(AipActivity $activity): array
    {
        return [
            '_key' => 'activity-'.$activity->id,
            'id' => $activity->id,
            'activity' => (string) $activity->activity,
            'person_responsible' => (string) ($activity->person_responsible ?? ''),
            'schedule' => (string) ($activity->schedule ?? ''),
            'venue' => (string) ($activity->venue ?? ''),
            'budget' => $activity->budget !== null ? (string) (float) $activity->budget : '',
            'budget_source' => (string) ($activity->budget_source ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private function blankProject(string $title = ''): array
    {
        return [
            '_key' => 'new-'.bin2hex(random_bytes(8)),
            'id' => null,
            'title' => $title,
            'objective' => '',
            'output_for_year' => '',
            'activities' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function blankActivity(): array
    {
        return [
            '_key' => 'new-'.bin2hex(random_bytes(8)),
            'id' => null,
            'activity' => '',
            'person_responsible' => '',
            'schedule' => '',
            'venue' => '',
            'budget' => '',
            'budget_source' => '',
        ];
    }

    /* --------------------------------------------------------------- rules */

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'projects' => ['array'],
            'projects.*.title' => ['required', 'string', 'max:255'],
            'projects.*.objective' => ['nullable', 'string', 'max:2000'],
            'projects.*.output_for_year' => ['nullable', 'string', 'max:2000'],
            'projects.*.activities' => ['array'],
            'projects.*.activities.*.activity' => ['required', 'string', 'max:2000'],
            'projects.*.activities.*.person_responsible' => ['nullable', 'string', 'max:255'],
            'projects.*.activities.*.schedule' => ['nullable', 'string', 'max:255'],
            'projects.*.activities.*.venue' => ['nullable', 'string', 'max:255'],
            'projects.*.activities.*.budget' => ['nullable', 'numeric', 'min:0'],
            'projects.*.activities.*.budget_source' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'title',
            'projects.*.title' => 'project title',
            'projects.*.objective' => 'project objective',
            'projects.*.output_for_year' => 'output for the year',
            'projects.*.activities.*.activity' => 'activity',
            'projects.*.activities.*.person_responsible' => 'person(s) responsible',
            'projects.*.activities.*.schedule' => 'schedule',
            'projects.*.activities.*.budget' => 'budget per activity',
            'projects.*.activities.*.budget_source' => 'budget source',
        ];
    }

    /* ------------------------------------------------------------- editing */

    public function addProject(): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        $this->projects[] = $this->blankProject();
    }

    public function removeProject(string $key): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        // A removed project takes its activities with it on save (the
        // reconciliation removes what is no longer in the grid).
        $this->projects = array_values(array_filter(
            $this->projects,
            fn (array $project): bool => ($project['_key'] ?? null) !== $key,
        ));
    }

    /** Copy a project and everything under it, right below itself. */
    public function duplicateProject(string $key): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        foreach ($this->projects as $index => $project) {
            if (($project['_key'] ?? null) !== $key) {
                continue;
            }

            $copy = $project;
            $copy['_key'] = 'new-'.bin2hex(random_bytes(8));
            $copy['id'] = null;

            // The copy carries the activities as NEW rows, so duplicating a
            // finished project does not make two projects share one activity.
            $copy['activities'] = array_map(function (array $activity): array {
                $activity['_key'] = 'new-'.bin2hex(random_bytes(8));
                $activity['id'] = null;

                return $activity;
            }, $project['activities'] ?? []);

            array_splice($this->projects, $index + 1, 0, [$copy]);

            break;
        }
    }

    public function addActivity(string $projectKey): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        foreach ($this->projects as $index => $project) {
            if (($project['_key'] ?? null) !== $projectKey) {
                continue;
            }

            $this->projects[$index]['activities'][] = $this->blankActivity();

            break;
        }
    }

    public function removeActivity(string $projectKey, string $activityKey): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        foreach ($this->projects as $index => $project) {
            if (($project['_key'] ?? null) !== $projectKey) {
                continue;
            }

            $this->projects[$index]['activities'] = array_values(array_filter(
                $project['activities'] ?? [],
                fn (array $activity): bool => ($activity['_key'] ?? null) !== $activityKey,
            ));

            break;
        }
    }

    public function duplicateActivity(string $projectKey, string $activityKey): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        foreach ($this->projects as $index => $project) {
            if (($project['_key'] ?? null) !== $projectKey) {
                continue;
            }

            foreach ($project['activities'] ?? [] as $activityIndex => $activity) {
                if (($activity['_key'] ?? null) !== $activityKey) {
                    continue;
                }

                $copy = $activity;
                $copy['_key'] = 'new-'.bin2hex(random_bytes(8));
                $copy['id'] = null;

                array_splice($this->projects[$index]['activities'], $activityIndex + 1, 0, [$copy]);

                break 2;
            }
        }
    }

    /* --------------------------------------------------------------- totals */

    /**
     * Per-project budget subtotals and the plan's grand total, recomputed on
     * every render from the grid's current values. The persisted total is
     * separate: this is what the owner sees before saving.
     *
     * @return array{projects: array<int, float>, grand: float}
     */
    #[Computed]
    public function totals(): array
    {
        $projects = [];

        foreach ($this->projects as $index => $project) {
            $projects[$index] = round($this->projectTotal($project), 2);
        }

        return [
            'projects' => $projects,
            'grand' => round(array_sum($projects), 2),
        ];
    }

    /** @param array<string, mixed> $project */
    private function projectTotal(array $project): float
    {
        $total = 0.0;

        foreach ($project['activities'] ?? [] as $activity) {
            $total += self::lineTotal($activity);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $activity
     */
    public static function lineTotal(array $activity): float
    {
        return is_numeric($activity['budget'] ?? null) ? round((float) $activity['budget'], 2) : 0.0;
    }

    /* ---------------------------------------------------------------- save */

    public function saveDraft(): void
    {
        if ($this->persist()) {
            $this->successMessage = 'Draft saved.';
        }
    }

    /**
     * Submit for review (a draft) or resubmit (a returned plan). The plan is
     * saved first, then archived as a version and handed to the reviewer.
     */
    public function submit(): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        if (! $this->persist()) {
            return;
        }

        $plan = $this->guard();
        $user = $this->user();

        if ($plan->isReturned()) {
            $plan->resubmit($user);
            $this->successMessage = 'Revision resubmitted for review.';
        } else {
            $plan->submit($user);
            $this->successMessage = 'Submitted for review.';
        }

        $this->reload($plan);

        $this->dispatch('aip-plan-submitted');
    }

    /**
     * Write the grid to the database. Returns false (with an error message)
     * only when the optimistic lock fails or the plan is not editable;
     * validation failures throw and are handled by Livewire.
     */
    private function persist(): bool
    {
        $plan = $this->guard();

        if (! $plan->isEditable()) {
            $this->errorMessage = 'This plan is out for review or already approved, so it can no longer be edited.';

            return false;
        }

        // Drop rows the owner added but never filled, then validate what
        // remains — an empty project or activity is a line not yet begun, not
        // an error.
        $this->projects = array_values(array_filter(
            $this->projects,
            fn (array $project): bool => ! self::isBlankProject($project),
        ));

        foreach ($this->projects as $index => $project) {
            $this->projects[$index]['activities'] = array_values(array_filter(
                $project['activities'] ?? [],
                fn (array $activity): bool => ! self::isBlankActivity($activity),
            ));
        }

        $this->validate();

        // Optimistic lock: only save if nobody else has written this plan.
        $currentVersion = (int) AipPlan::whereKey($plan->id)->value('version');

        if ($currentVersion !== $this->loadedVersion) {
            $this->errorMessage = 'This plan changed elsewhere — reload the page before saving.';

            return false;
        }

        DB::transaction(function () use ($plan): void {
            $plan->title = self::nullIfBlank($this->title);
            $plan->updated_by = Auth::id();
            $plan->save();

            $keptProjectIds = [];

            foreach ($this->projects as $projectIndex => $projectData) {
                $project = ! empty($projectData['id'])
                    ? AipProject::find((int) $projectData['id'])
                    : new AipProject();

                if ($project === null) {
                    $project = new AipProject();
                }

                // Never let a crafted call move a project across plans.
                if ($project->exists && (int) $project->aip_plan_id !== (int) $plan->id) {
                    continue;
                }

                $project->fill([
                    'aip_plan_id' => $plan->id,
                    'position' => $projectIndex + 1,
                    'title' => (string) ($projectData['title'] ?? ''),
                    'objective' => self::nullIfBlank($projectData['objective'] ?? null),
                    'output_for_year' => self::nullIfBlank($projectData['output_for_year'] ?? null),
                ]);
                $project->save();

                $this->projects[$projectIndex]['id'] = $project->id;
                $this->projects[$projectIndex]['_key'] = 'project-'.$project->id;

                $keptActivityIds = [];

                foreach ($projectData['activities'] ?? [] as $activityIndex => $activityData) {
                    $activity = ! empty($activityData['id'])
                        ? AipActivity::find((int) $activityData['id'])
                        : new AipActivity();

                    if ($activity === null) {
                        $activity = new AipActivity();
                    }

                    if ($activity->exists && (int) $activity->aip_plan_id !== (int) $plan->id) {
                        continue;
                    }

                    $activity->fill([
                        'aip_plan_id' => $plan->id,
                        'aip_project_id' => $project->id,
                        'position' => $activityIndex + 1,
                        'activity' => (string) ($activityData['activity'] ?? ''),
                        'person_responsible' => self::nullIfBlank($activityData['person_responsible'] ?? null),
                        'schedule' => self::nullIfBlank($activityData['schedule'] ?? null),
                        'venue' => self::nullIfBlank($activityData['venue'] ?? null),
                        'budget' => is_numeric($activityData['budget'] ?? null) ? (float) $activityData['budget'] : null,
                        'budget_source' => self::nullIfBlank($activityData['budget_source'] ?? null),
                    ]);
                    $activity->save();

                    $this->projects[$projectIndex]['activities'][$activityIndex]['id'] = $activity->id;
                    $this->projects[$projectIndex]['activities'][$activityIndex]['_key'] = 'activity-'.$activity->id;

                    $keptActivityIds[] = $activity->id;
                }

                $project->activities()->whereNotIn('id', $keptActivityIds ?: [0])->delete();
                $keptProjectIds[] = $project->id;
            }

            // Projects no longer in the grid go, and their activities with
            // them (the cascade).
            $plan->projects()->whereNotIn('id', $keptProjectIds ?: [0])->delete();

            $plan->bumpVersion();
        });

        $this->reload(AipPlan::findOrFail($plan->id));

        return true;
    }

    private function reload(AipPlan $plan): void
    {
        $this->loadedVersion = (int) $plan->version;
        $this->title = (string) ($plan->title ?? '');
        $this->loadProjects($plan);
    }

    /** @param array<string, mixed> $project */
    private static function isBlankProject(array $project): bool
    {
        if (trim((string) ($project['title'] ?? '')) !== ''
            || trim((string) ($project['objective'] ?? '')) !== ''
            || trim((string) ($project['output_for_year'] ?? '')) !== '') {
            return false;
        }

        foreach ($project['activities'] ?? [] as $activity) {
            if (! self::isBlankActivity($activity)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $activity */
    private static function isBlankActivity(array $activity): bool
    {
        foreach (['activity', 'person_responsible', 'schedule', 'venue', 'budget', 'budget_source'] as $field) {
            if (trim((string) ($activity[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* ---------------------------------------------------------- WFP import */

    /**
     * Copy the linked WFP's lines in as activities. Offered only while the
     * plan is still empty, so a second click can never duplicate the plan's
     * contents — the owner edits what arrives.
     */
    public function importFromWfp(): void
    {
        $plan = $this->guard();
        $this->assertEditable($plan);

        if ($this->projects !== []) {
            $this->errorMessage = 'The plan already has projects. Clear them first if you want to start again from the WFP.';

            return;
        }

        $wfp = $plan->wfpPlan;

        if ($wfp === null) {
            $this->errorMessage = 'No Work and Financial Plan is linked for '.$plan->fiscalYearLabel().'. Generate one first, then reopen this plan.';

            return;
        }

        $items = $wfp->items()->get();

        if ($items->isEmpty()) {
            $this->errorMessage = 'The linked WFP has no lines to copy.';

            return;
        }

        // Group by the WFP's Program/Activity: that is the closest thing a
        // WFP has to an AIP project, and its lines become the activities.
        $groups = [];

        foreach ($items as $item) {
            $key = trim((string) ($item->program_activity ?? '')) ?: 'WFP activities';
            $groups[$key][] = $item;
        }

        foreach ($groups as $programActivity => $group) {
            $first = $group[0];

            $project = $this->blankProject(mb_substr($programActivity, 0, 255));
            $project['objective'] = (string) ($first->objective ?? '');
            $project['output_for_year'] = (string) ($first->major_output ?? '');

            foreach ($group as $item) {
                $activity = $this->blankActivity();
                $activity['activity'] = (string) (($item->major_output ?? '') !== ''
                    ? $item->major_output
                    : ($item->account_title ?? $programActivity));
                $activity['schedule'] = $item->month !== null
                    ? (MonthlyExpenditureMatrix::MONTH_ABBREVIATIONS[(int) $item->month] ?? (string) $item->month)
                    : '';
                $activity['budget'] = $item->financial_target !== null ? (string) (float) $item->financial_target : '';
                $activity['budget_source'] = (string) ($item->funding_source ?? '');

                $project['activities'][] = $activity;
            }

            $this->projects[] = $project;
        }

        $this->successMessage = 'Copied '.count($items).' line(s) from the linked WFP — review and adjust them.';
    }

    /* ------------------------------------------------------ MOV attachment */

    /**
     * Attach the approved AIP to its MOV requirement. The generated workbook
     * becomes the evidence document on the checklist's AIP line — a normal
     * user_movs row, so it shows in the checklist, carries its review state
     * and downloads through the MOV route.
     */
    public function attachToMov(): void
    {
        $plan = $this->guard();
        $user = $this->user();

        if (! $plan->isApproved()) {
            $this->errorMessage = 'Only an approved AIP can be attached to a MOV.';

            return;
        }

        $requirement = AipPlan::movRequirement();

        if ($requirement === null) {
            $this->errorMessage = 'No MOV requirement mentions the Annual Implementation Plan, so there is nothing to attach to.';

            return;
        }

        $disk = Storage::disk(config('mov.disk', 'local'));
        $directory = trim((string) config('mov.directory', 'mov-uploads'), '/').'/'.$user->id;

        $bytes = AipXlsxExporter::bytes($plan, $user);
        $fileName = AipXlsxExporter::safeName($plan->exportBaseName()).'.xlsx';
        $storedName = uniqid('aip-', true).'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName);

        // The path is built here and passed to put(): FilesystemAdapter::put()
        // returns a bool, not the path, so it must not be assigned as one.
        $path = $directory.'/'.$storedName;

        if (! $disk->put($path, $bytes)) {
            $this->errorMessage = 'The AIP document could not be stored. Try again.';

            return;
        }

        // One document per requirement: replacing removes the file it replaces
        // so the old copy is not orphaned on disk.
        $existing = UserMov::query()
            ->where('user_id', $user->id)
            ->where('mov_requirement_id', $requirement->id)
            ->first();

        if ($existing !== null && $existing->stored_path !== '') {
            $disk->delete($existing->stored_path);
        }

        UserMov::updateOrCreate(
            ['user_id' => $user->id, 'mov_requirement_id' => $requirement->id],
            [
                'aip_plan_id' => $plan->id,
                'original_name' => $fileName,
                'stored_path' => $path,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size_bytes' => strlen($bytes),
                'status' => UserMov::STATUS_UPLOADED,
                'remarks' => null,
                'uploaded_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        );

        $this->successMessage = 'Approved AIP attached to '.$requirement->label().' on the MOV checklist.';
    }

    /* -------------------------------------------------------------- preview */

    public function openPreview(): void
    {
        $this->showPreview = true;
    }

    public function closePreview(): void
    {
        $this->showPreview = false;
    }

    /* ---------------------------------------------------------------- misc */

    private function assertEditable(AipPlan $plan): void
    {
        if (! $plan->isEditable()) {
            abort(403, 'This plan can no longer be edited.');
        }
    }

    public function clearSuccess(): void
    {
        $this->successMessage = null;
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    /* ------------------------------------------------------------ computed */

    #[Computed]
    public function plan(): AipPlan
    {
        return AipPlan::with(['school.district', 'wfpPlan', 'approvedBy'])->findOrFail($this->planId);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->plan->isEditable();
    }

    /** @return array<int, string> */
    #[Computed]
    public function budgetSources(): array
    {
        return (array) config('aip.budget_sources', []);
    }

    #[Computed]
    public function backUrl(): string
    {
        return route('aip.index');
    }

    public function render()
    {
        return view('livewire.aip.editor', [
            'plan' => $this->plan,
        ]);
    }
}
