<?php

namespace Tests\Feature;

use App\Livewire\ViewerSubmissionStats;
use App\Models\AipPlan;
use App\Models\District;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\SipSubmission;
use App\Models\User;
use App\Models\WfpSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ViewerSubmissionDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_dashboard_summarizes_review_queues_and_sip_records(): void
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
                'opcrf_part' => OpcrfSubmission::FULL_WORKBOOK,
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

        OpcrfSubmission::create([
            'user_id' => $schoolHead->id,
            'opcrf_part' => OpcrfSubmission::PART_ONE,
            'employee_name' => 'School Head',
            'position' => 'School Head',
            'review_period' => 'CY 2026',
            'division_office' => 'District I',
            'objectives' => 'Part I objectives',
            'accomplishments' => 'Part I accomplishments',
            'self_rating' => 4,
            'status' => OpcrfSubmission::STATUS_PENDING,
            'reviewer_id' => $viewer->id,
            'assigned_to' => $viewer->id,
            'submitted_at' => now(),
        ]);

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

        SipSubmission::create([
            'school_id' => $school->id,
            'division' => 'ELEMENTARY',
            'uploaded_by' => $schoolHead->id,
            'original_file_name' => 'school-sip.pdf',
            'file_path' => 'sip/'.$school->id.'/school-sip.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
            'uploaded_at' => now(),
        ]);

        $component = Livewire::actingAs($viewer)->test(ViewerSubmissionStats::class);
        $this->assertSame([
            'total' => 7,
            'aip' => 3,
            'opcrf' => 2,
            'wfp' => 2,
            'sip' => 1,
            'awaiting' => 5,
            'in_review' => 1,
            'returned' => 0,
            'approved' => 2,
        ], $component->instance()->stats());
        $component->assertDontSee('SIP');

        $this->actingAs($viewer)
            ->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire(ViewerSubmissionStats::class)
            ->assertSee('School Head submissions')
            ->assertSee('AIP Review')
            ->assertSee('OPCRF')
            ->assertSee('WFP Review')
            ->assertSee('SIP')
            ->assertSee(route('opcrf.review'), false)
            ->assertDontSee(route('opcrf.index'), false)
            ->assertSee(route('sip.index'), false)
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
