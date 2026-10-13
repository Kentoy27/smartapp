<?php

namespace Tests\Feature;

use App\Livewire\AipEditor;
use App\Livewire\AipManager;
use App\Livewire\AipReview;
use App\Models\AipPlan;
use App\Models\District;
use App\Models\MovCategory;
use App\Models\MovPart;
use App\Models\MovRequirement;
use App\Models\School;
use App\Models\User;
use App\Models\UserMov;
use App\Models\WfpItem;
use App\Models\WfpPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Annual Implementation Plan workflow: create → draft → submit → review →
 * approve / return → resubmit, with the permissions, budget arithmetic,
 * version history, WFP link and MOV attachment it promises.
 */
class AipModuleTest extends TestCase
{
    use RefreshDatabase;

    private District $district;

    private School $school;

    private School $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->district = District::create(['name' => 'District I']);
        $this->school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $this->district->id]);
        $this->otherSchool = School::create(['name' => 'Beta Elementary School', 'district_id' => $this->district->id]);
    }

    private function schoolHead(?School $school = null): User
    {
        return User::factory()->create([
            'name' => 'School Head',
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => ($school ?? $this->school)->id,
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'name' => 'Super Admin',
            'username' => 'Eve',
            'role' => 'superadmin',
            'is_superadmin' => true,
        ]);
    }

    private function draftPlan(?User $owner = null): AipPlan
    {
        $owner ??= $this->schoolHead();

        return AipPlan::create([
            'school_id' => $owner->school_id,
            'year' => 2026,
            'created_by' => $owner->id,
        ]);
    }

    /* ----------------------------------------------------------- creation */

    public function test_the_manager_creates_one_plan_per_school_and_year_and_links_the_wfp(): void
    {
        $sh = $this->schoolHead();
        WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);

        Livewire::actingAs($sh)
            ->test(AipManager::class)
            ->set('year', 2026)
            ->set('title', 'Annual Implementation Plan 2026')
            ->call('create')
            ->assertRedirect(route('aip.plans.edit', ['plan' => AipPlan::first()->id]));

        $plan = AipPlan::firstOrFail();

        $this->assertSame($this->school->id, $plan->school_id);
        $this->assertSame(AipPlan::STATUS_DRAFT, $plan->status);
        $this->assertNotNull($plan->wfp_plan_id, 'the plan should link the school WFP for the same year');

        // Asking again opens the SAME plan rather than creating a second one.
        Livewire::actingAs($sh)->test(AipManager::class)->set('year', 2026)->call('create');

        $this->assertSame(1, AipPlan::count());
    }

    public function test_an_account_without_a_school_is_refused_a_plan(): void
    {
        $sh = User::factory()->create(['role' => 'user', 'is_superadmin' => false, 'school_id' => null]);

        Livewire::actingAs($sh)
            ->test(AipManager::class)
            ->set('year', 2026)
            ->call('create')
            ->assertSet('errorMessage', fn (?string $message): bool => $message !== null);

        $this->assertSame(0, AipPlan::count());
    }

    public function test_the_editor_page_renders_the_official_form_columns(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->draftPlan($sh);
        $plan->projects()->create(['position' => 1, 'title' => 'Project One']);

        $this->actingAs($sh)
            ->get(route('aip.plans.edit', ['plan' => $plan->id]))
            ->assertOk()
            ->assertSee('Annual Implementation Plan')
            ->assertSee('Save draft')
            ->assertSee('Project title')
            ->assertSee('Project objective')
            ->assertSee('Output for the year')
            ->assertSee('Person(s) Responsible')
            ->assertSee('Budget per Activity')
            ->assertSee('Budget Source');
    }

    /* ------------------------------------------------------------ editing */

    public function test_the_editor_saves_projects_and_activities_with_budget_totals(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->draftPlan($sh);

        $component = Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->call('addProject')
            ->set('title', 'Annual Implementation Plan')
            ->set('projects.0.title', 'Improve Reading Proficiency')
            ->set('projects.0.objective', 'Raise every learner to grade level')
            ->set('projects.0.output_for_year', '90% of learners at grade level');

        $key = $component->instance()->projects[0]['_key'];

        $component
            ->call('addActivity', $key)
            ->set('projects.0.activities.0.activity', 'Reading camp')
            ->set('projects.0.activities.0.person_responsible', 'Grade Chair')
            ->set('projects.0.activities.0.schedule', 'Q1')
            ->set('projects.0.activities.0.venue', 'Library')
            ->set('projects.0.activities.0.budget', '15000.50')
            ->set('projects.0.activities.0.budget_source', 'MOOE')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $plan->refresh();

        $this->assertSame('Annual Implementation Plan', $plan->title);
        $this->assertSame(1, $plan->projects()->count());
        $this->assertSame(1, $plan->activities()->count());
        $this->assertSame(15000.5, $plan->annualBudgetTotal());
        $this->assertSame(['MOOE' => 15000.5], $plan->budgetBySource());

        $activity = $plan->activities()->firstOrFail();
        $this->assertSame('Reading camp', $activity->activity);
        $this->assertSame('MOOE', $activity->budget_source);
        $this->assertSame('Q1', $activity->schedule);

        // The content version advanced, so a stale editor is detectable.
        $this->assertSame(1, (int) $plan->version);
    }

    public function test_blank_projects_are_dropped_rather_than_rejected(): void
    {
        $sh = $this->schoolHead();
        $plan = $this->draftPlan($sh);

        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            // A second, empty project nobody filled in.
            ->call('addProject')
            ->call('addProject')
            ->set('projects.0.title', 'Kept project')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertSame(1, AipPlan::firstOrFail()->projects()->count());
    }

    public function test_a_cross_school_editor_is_refused(): void
    {
        $owner = $this->schoolHead($this->school);
        $intruder = $this->schoolHead($this->otherSchool);
        $plan = $this->draftPlan($owner);

        $this->actingAs($intruder)->get(route('aip.plans.edit', ['plan' => $plan->id]))->assertNotFound();
        Livewire::actingAs($intruder)->test(AipEditor::class, ['plan' => $plan])->assertStatus(404);
    }

    /* ---------------------------------------------------------- workflow */

    public function test_submitting_archives_a_version_and_review_approves_it(): void
    {
        $sh = $this->schoolHead();
        $admin = $this->superadmin();
        $plan = $this->draftPlan($sh);

        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->call('addProject')
            ->set('projects.0.title', 'Project One')
            ->call('submit')
            ->assertHasNoErrors();

        $plan->refresh();

        $this->assertSame(AipPlan::STATUS_PENDING, $plan->status);
        $this->assertNotNull($plan->submitted_at);
        $this->assertSame(1, $plan->versions()->count(), 'the first submission is archived as Version 1');
        $this->assertSame($admin->id, $plan->reviewer_id, 'the plan routes to the configured reviewer');

        // The owner can no longer edit a plan that is out for review.
        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->call('saveDraft')
            ->assertSet('errorMessage', fn (?string $m): bool => $m !== null);

        Livewire::actingAs($admin)
            ->test(AipReview::class)
            ->call('openReview', $plan->id)
            ->set('reviewRemarks', 'Well prepared.')
            ->call('approve');

        $plan->refresh();

        $this->assertSame(AipPlan::STATUS_APPROVED, $plan->status);
        $this->assertNotNull($plan->approved_at);
        $this->assertSame($admin->id, $plan->approved_by);
        $this->assertSame('Well prepared.', $plan->review_remarks);
        $this->assertTrue($plan->isApproved());
    }

    public function test_returning_requires_remarks_and_a_resubmission_keeps_both_versions(): void
    {
        $sh = $this->schoolHead();
        $admin = $this->superadmin();
        $plan = $this->draftPlan($sh);

        $plan->projects()->create(['position' => 1, 'title' => 'Project One']);
        $plan->submit($sh);

        // A return without remarks is refused.
        Livewire::actingAs($admin)
            ->test(AipReview::class)
            ->call('openReview', $plan->id)
            ->call('returnForRevision')
            ->assertHasErrors(['reviewRemarks']);

        Livewire::actingAs($admin)
            ->test(AipReview::class)
            ->call('openReview', $plan->id)
            ->set('reviewRemarks', 'Add the budget source for each activity.')
            ->call('returnForRevision')
            ->assertHasNoErrors();

        $plan->refresh();
        $this->assertSame(AipPlan::STATUS_RETURNED, $plan->status);
        $this->assertNull($plan->assigned_to, 'a returned plan is back with the school, not held by a reviewer');
        $this->assertSame('Add the budget source for each activity.', $plan->review_remarks);

        // The owner revises and resubmits: a second version is archived, the
        // first is never overwritten.
        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->set('projects.0.title', 'Project One (revised)')
            ->call('submit')
            ->assertHasNoErrors();

        $plan->refresh();

        $this->assertSame(AipPlan::STATUS_RESUBMITTED, $plan->status);
        $this->assertSame(2, $plan->versions()->count());
        // reorder(), not orderByDesc(): the relation already carries its own
        // ORDER BY, so an appended one would be ignored.
        $this->assertSame('Project One', $plan->versions()->reorder('version_number')->first()->snapshot['projects'][0]['title']);
        $this->assertSame('Project One (revised)', $plan->versions()->reorder('version_number', 'desc')->first()->snapshot['projects'][0]['title']);
        $this->assertSame($admin->id, $plan->assigned_to, 'the revision routes back to the reviewing superadmin');
    }

    public function test_a_draft_is_invisible_to_reviewers_until_it_is_submitted(): void
    {
        $sh = $this->schoolHead();
        $admin = $this->superadmin();
        $plan = $this->draftPlan($sh);

        $component = Livewire::actingAs($admin)->test(AipReview::class);
        $this->assertSame(0, $component->instance()->plans->total());

        $this->actingAs($admin)->get(route('aip.plans.print', ['plan' => $plan->id]))->assertNotFound();

        $plan->submit($sh);

        $component = Livewire::actingAs($admin)->test(AipReview::class);
        $this->assertSame(1, $component->instance()->plans->total());
        $this->actingAs($admin)->get(route('aip.plans.print', ['plan' => $plan->id]))->assertOk();
    }

    public function test_the_review_modal_can_show_an_archived_version(): void
    {
        $sh = $this->schoolHead();
        $admin = $this->superadmin();
        $plan = $this->draftPlan($sh);

        $plan->projects()->create(['position' => 1, 'title' => 'Original Title']);
        $plan->submit($sh);
        $version = $plan->versions()->firstOrFail();

        $plan->projects()->firstOrFail()->update(['title' => 'Changed Title']);

        $component = Livewire::actingAs($admin)
            ->test(AipReview::class)
            ->call('openReview', $plan->id)
            ->call('showVersion', $version->id);

        $this->assertSame('Original Title', $component->instance()->reviewContents['projects'][0]['title']);
    }

    /* ------------------------------------------------------- the WFP link */

    public function test_the_editor_can_copy_lines_from_the_linked_wfp(): void
    {
        $sh = $this->schoolHead();

        $wfp = WfpPlan::create(['school_id' => $this->school->id, 'year' => 2026, 'status' => WfpPlan::STATUS_DRAFT]);
        WfpItem::create([
            'wfp_plan_id' => $wfp->id,
            'group_key' => 'g1',
            'position' => 1,
            'month' => 3,
            'objective' => 'Improve reading outcomes',
            'program_activity' => 'Reading Program',
            'major_output' => 'Reading camp conducted',
            'financial_target' => 5000,
            'funding_source' => 'MOOE',
        ]);

        $plan = AipPlan::create([
            'school_id' => $this->school->id,
            'year' => 2026,
            'created_by' => $sh->id,
            'wfp_plan_id' => $wfp->id,
        ]);

        $component = Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->call('importFromWfp')
            ->assertHasNoErrors();

        $projects = $component->instance()->projects;

        $this->assertCount(1, $projects);
        $this->assertSame('Reading Program', $projects[0]['title']);
        $this->assertSame('Improve reading outcomes', $projects[0]['objective']);
        $this->assertCount(1, $projects[0]['activities']);
        $this->assertSame('5000', $projects[0]['activities'][0]['budget']);
        $this->assertSame('MOOE', $projects[0]['activities'][0]['budget_source']);
        $this->assertSame('Mar', $projects[0]['activities'][0]['schedule']);

        $component->call('saveDraft')->assertHasNoErrors();
        $this->assertSame(1, $plan->fresh()->activities()->count());
    }

    /* ------------------------------------------------------- MOV attaching */

    public function test_an_approved_aip_can_be_attached_to_its_mov_requirement(): void
    {
        Storage::fake('local');

        $sh = $this->schoolHead();
        $plan = $this->draftPlan($sh);
        $plan->projects()->create(['position' => 1, 'title' => 'Project One']);

        $part = MovPart::create(['name' => 'PART 1', 'part_order' => 1]);
        $category = MovCategory::create(['mov_part_id' => $part->id, 'name' => 'A', 'category_order' => 1]);
        $requirement = MovRequirement::create([
            'mov_category_id' => $category->id,
            'mov_number' => 1,
            'title' => 'Approved Work and Financial Plan (WFP) and Annual Implementation Plan (AIP)',
            'is_required' => true,
            'display_order' => 1,
        ]);

        // Not approved yet → refused.
        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan])
            ->call('attachToMov')
            ->assertSet('errorMessage', fn (?string $m): bool => $m !== null);

        $this->assertSame(0, UserMov::count());

        $plan->submit($sh);
        $plan->markApproved($this->superadmin());

        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan->fresh()])
            ->call('attachToMov')
            ->assertHasNoErrors();

        $mov = UserMov::firstOrFail();

        $this->assertSame($sh->id, $mov->user_id);
        $this->assertSame($requirement->id, $mov->mov_requirement_id);
        $this->assertSame($plan->id, $mov->aip_plan_id);
        $this->assertStringEndsWith('.xlsx', $mov->original_name);
        Storage::disk('local')->assertExists($mov->stored_path);

        // Attaching again replaces the document rather than duplicating it.
        Livewire::actingAs($sh)
            ->test(AipEditor::class, ['plan' => $plan->fresh()])
            ->call('attachToMov');

        $this->assertSame(1, UserMov::count());
    }

    /* -------------------------------------------------------------- export */

    public function test_the_export_and_print_routes_serve_the_plan_to_its_owner_and_reviewer(): void
    {
        $sh = $this->schoolHead();
        $admin = $this->superadmin();
        $plan = $this->draftPlan($sh);
        $plan->projects()->create(['position' => 1, 'title' => 'Project One', 'objective' => 'Objective One']);

        $response = $this->actingAs($sh)->get(route('aip.plans.export', ['plan' => $plan->id]))->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
        $this->assertStringStartsWith('PK', (string) $response->getContent());

        $this->actingAs($sh)
            ->get(route('aip.plans.print', ['plan' => $plan->id]))
            ->assertOk()
            ->assertSee('Annual Implementation Plan')
            ->assertSee('TOTAL BUDGET');

        // The reviewer may fetch a submitted plan's copies.
        $plan->submit($sh);

        $this->actingAs($admin)->get(route('aip.plans.export', ['plan' => $plan->id]))->assertOk();
        $this->actingAs($admin)->get(route('aip.plans.print', ['plan' => $plan->id]))->assertOk();

        // A stranger may not.
        $this->actingAs($this->schoolHead($this->otherSchool))
            ->get(route('aip.plans.export', ['plan' => $plan->id]))
            ->assertNotFound();
    }
}
