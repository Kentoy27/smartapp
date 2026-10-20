<?php

namespace Tests\Feature;

use App\Livewire\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The /aip landing page and its navigation entry.
 *
 * Authoring an AIP is the School Head's job (the school owns the plan), so
 * the page is gated to the SH role; a superadmin reaches AIPs through the
 * "Review AIP" queue instead, and the SDS Viewer never sees either.
 */
class AipPageTest extends TestCase
{
    use RefreshDatabase;

    private function schoolHead(): User
    {
        return User::factory()->create(['role' => 'user', 'is_superadmin' => false]);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin', 'is_superadmin' => true]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('aip.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_school_heads_can_open_the_empty_aip_table(): void
    {
        $user = $this->schoolHead();

        $response = $this->actingAs($user)
            ->get(route('aip.index'))
            ->assertOk()
            ->assertSee('Annual Implementation Plan (AIP)')
            ->assertSee('AIP Records')
            ->assertSee('<table class="data-table aip-table">', false)
            ->assertSee('No AIP records yet.');

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('aip.index'), '/').'"\s+class="nav-item active"/',
            $response->getContent()
        );
    }

    public function test_the_authoring_page_is_closed_to_superadmins_and_viewers(): void
    {
        $this->actingAs($this->superadmin())->get(route('aip.index'))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get(route('aip.index'))->assertNotFound();
    }

    public function test_aip_is_listed_in_the_files_sidebar_for_school_heads_only(): void
    {
        $items = collect(Livewire::actingAs($this->schoolHead())->test(Sidebar::class)->instance()->items());

        $aip = $items->firstWhere('label', 'AIP');

        $this->assertNotNull($aip);
        $this->assertSame('aip.index', $aip['route']);
        $this->assertSame('Files', $aip['section']);

        // A superadmin authors no AIP: their entry is the review queue.
        $adminItems = collect(Livewire::actingAs($this->superadmin())->test(Sidebar::class)->instance()->items());

        $this->assertNull($adminItems->firstWhere('label', 'AIP'));
        $this->assertSame('aip.review', $adminItems->firstWhere('label', 'Review AIP')['route']);

        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $budgetItems = collect(Livewire::actingAs($budget)->test(Sidebar::class)->instance()->items());
        $this->assertSame('aip.review', $budgetItems->firstWhere('label', 'Review AIP')['route']);
        $this->assertSame('wfp.submissions.index', $budgetItems->firstWhere('label', 'WFP Uploads')['route']);
        $this->assertNull($budgetItems->firstWhere('label', 'AIP'));
        $this->assertNull($budgetItems->firstWhere('label', 'OPCRF'));
        $this->assertNull($budgetItems->firstWhere('label', 'MOV'));
        $this->assertNull($budgetItems->firstWhere('label', 'APP'));
    }

    public function test_the_review_queue_is_open_to_budget_reviewers_but_not_staff(): void
    {
        $this->get(route('aip.review'))->assertRedirect(route('login'));
        $this->actingAs($this->schoolHead())->get(route('aip.review'))->assertNotFound();
        $this->actingAs($this->superadmin())->get(route('aip.review'))->assertOk()->assertSee('Review AIP');

        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);
        $this->actingAs($budget)
            ->get(route('aip.review'))
            ->assertOk()
            ->assertSee('Review AIP');
        $this->get(route('aip.index'))->assertNotFound();
        $this->get(route('wfp.index'))->assertNotFound();
        $this->get(route('opcrf.review'))->assertNotFound();
        $this->get(route('users.index'))->assertNotFound();
    }

    public function test_budget_dashboard_only_shows_review_workspaces(): void
    {
        $budget = User::factory()->create(['role' => 'budget', 'is_superadmin' => false]);

        $this->actingAs($budget)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('BUDGET REVIEW WORKSPACE')
            ->assertSee('Review AIP')
            ->assertSee('WFP Uploads')
            ->assertDontSee('Performance plan')
            ->assertDontSee('Open MOV checklist');
    }
}
