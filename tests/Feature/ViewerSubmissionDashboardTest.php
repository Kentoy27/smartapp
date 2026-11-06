<?php

namespace Tests\Feature;

use App\Livewire\ViewerSubmissionStats;
use App\Models\AipPlan;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ViewerSubmissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_dashboard_summarizes_visible_school_head_submissions_without_sip(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $otherViewer = User::factory()->create(['role' => 'viewer', 'is_superadmin' => false]);
        $district = District::create(['name' => 'District I']);
        $school = School::create(['name' => 'Alpha Elementary School', 'district_id' => $district->id]);
        $schoolHead = User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);

        foreach ([
            [2025, AipPlan::STATUS_PENDING],
            [2026, AipPlan::STATUS_FORWARDED],
            [2027, AipPlan::STATUS_APPROVED],
        ] as [$year, $status]) {
            AipPlan::create([
                'school_id' => $school->id,
                'year' => $year,
                'created_by' => $schoolHead->id,
                'status' => $status,
                'submitted_at' => now(),
                'reviewer_id' => $viewer->id,
                'assigned_to' => $viewer->id,
            ]);
        }

        foreach ([
            [$viewer, OpcrfSubmission::STATUS_PENDING],
            [$viewer, OpcrfSubmission::STATUS_RETURNED],
            [$otherViewer, OpcrfSubmission::STATUS_PENDING],
        ] as [$assignedViewer, $status]) {
            OpcrfSubmission::create([
                'user_id' => $schoolHead->id,
                'employee_name' => 'School Head',
                'position' => 'School Head',
                'review_period' => 'CY 2026',
                'division_office' => 'District I',
                'objectives' => 'School objectives',
                'accomplishments' => 'School accomplishments',
                'self_rating' => 4,
                'status' => $status,
                'reviewer_id' => $assignedViewer->id,
                'assigned_to' => $assignedViewer->id,
                'submitted_at' => now(),
            ]);
        }

        foreach ([
            ['ELEMENTARY', null],
            ['ELEMENTARY', 'approved'],
            ['JUNIOR_HIGH', null],
        ] as [$division, $reviewStatus]) {
            WfpSubmission::create([
                'user_id' => $schoolHead->id,
                'school_division' => $division,
                'original_file_name' => 'school-wfp.xlsx',
                'file_path' => 'wfp/'.$schoolHead->id.'/school-wfp.xlsx',
                'status' => WfpSubmission::STATUS_UPLOADED,
                'review_status' => $reviewStatus,
                'uploaded_at' => now(),
            ]);
        }

        $component = Livewire::actingAs($viewer)->test(ViewerSubmissionStats::class);
        $this->assertSame([
            'total' => 8,
            'aip' => 3,
            'opcrf' => 3,
            'wfp' => 2,
            'awaiting' => 4,
            'in_review' => 1,
            'returned' => 1,
            'approved' => 2,
        ], $component->instance()->stats());
        $component->assertDontSee('SIP');

        $this->actingAs($viewer)
            ->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire(ViewerSubmissionStats::class)
            ->assertSee('School Head submissions')
            ->assertSee('AIP Review')
            ->assertSee('OPCRF Review')
            ->assertSee('WFP Review')
            ->assertSee(route('opcrf.index'), false)
            ->assertDontSee(route('opcrf.review'), false)
            ->assertDontSee('Open MOV checklist');
    }

    public function test_school_heads_cannot_open_viewer_submission_statistics(): void
    {
        $schoolHead = User::factory()->create(['role' => 'user', 'is_superadmin' => false]);

        Livewire::actingAs($schoolHead)
            ->test(ViewerSubmissionStats::class)
            ->assertNotFound();
    }
}
