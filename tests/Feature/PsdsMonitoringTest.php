<?php

namespace Tests\Feature;

use App\Livewire\PsdsMonitoring;
use App\Models\AipPlan;
use App\Models\AipReview;
use App\Models\District;
use App\Models\OpcrfReview;
use App\Models\OpcrfSubmission;
use App\Models\School;
use App\Models\User;
use App\Models\WfpApproval;
use App\Models\WfpPlan;
use App\Models\WfpSubmission;
use App\Support\PsdsMonitoringReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The PSDS monitoring report: the assigned-district scope, the AIP/OPCRF/WFP
 * status mapping, the filters, the read-only detail modal and the CSV export.
 *
 * Every test starts from the same premise: the report is built from the
 * account's own district assignments, and a district or school id carried in
 * a filter can only narrow that scope — never widen it.
 */
class PsdsMonitoringTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------ helpers */

    private function district(string $name): District
    {
        return District::create(['name' => $name]);
    }

    private function school(District $district, string $name, string $code): School
    {
        return School::create([
            'district_id' => $district->id,
            'name' => $name,
            'school_id' => $code,
        ]);
    }

    /** @param array<int, District> $districts */
    private function psds(array $districts = []): User
    {
        $psds = User::factory()->create([
            'name' => 'Paula District Supervisor',
            'role' => 'psds',
            'is_superadmin' => false,
        ]);

        $psds->syncAssignedDistricts(array_map(
            fn (District $district): int => (int) $district->id,
            $districts,
        ));

        return $psds;
    }

    private function schoolHead(School $school): User
    {
        return User::factory()->create([
            'role' => 'user',
            'is_superadmin' => false,
            'school_id' => $school->id,
        ]);
    }

    private function aip(School $school, int $year, string $status, ?Carbon $submittedAt = null): AipPlan
    {
        return AipPlan::create([
            'school_id' => $school->id,
            'year' => $year,
            'title' => 'AIP '.$year,
            'status' => $status,
            'submitted_at' => $submittedAt
                ?? ($status === AipPlan::STATUS_DRAFT ? null : now()->subDays(5)),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function opcrf(User $head, string $status, int $year, array $overrides = []): OpcrfSubmission
    {
        return OpcrfSubmission::create(array_merge([
            'user_id' => $head->id,
            'employee_name' => $head->name,
            'position' => 'Teacher I',
            'review_period' => 'CY '.$year,
            'division_office' => 'District Office',
            'objectives' => 'Objective',
            'accomplishments' => 'Accomplishment',
            'self_rating' => 4.0,
            'status' => $status,
            'submitted_at' => now()->subDays(3),
        ], $overrides));
    }

    private function wfpPlan(School $school, int $year, string $status, ?Carbon $submittedAt = null): WfpPlan
    {
        return WfpPlan::create([
            'school_id' => $school->id,
            'year' => $year,
            'status' => $status,
            'submitted_at' => $submittedAt
                ?? ($status === WfpPlan::STATUS_DRAFT ? null : now()->subDays(4)),
            'approved_at' => $status === WfpPlan::STATUS_FINALIZED ? now()->subDays(2) : null,
        ]);
    }

    private function wfpUpload(User $head, string $schoolYear, ?Carbon $uploadedAt = null): WfpSubmission
    {
        return WfpSubmission::create([
            'user_id' => $head->id,
            'original_file_name' => 'wfp-'.$head->id.'.xlsx',
            'file_path' => 'wfp/'.$head->id.'/wfp.xlsx',
            'school_year' => $schoolYear,
            'status' => WfpSubmission::STATUS_UPLOADED,
            'uploaded_at' => $uploadedAt ?? now()->subDays(6),
        ]);
    }

    /* -------------------------------------------------------------- scope */

    public function test_the_report_only_covers_schools_in_assigned_districts(): void
    {
        $year = (int) now()->year;
        $north = $this->district('North District');
        $south = $this->district('South District');
        $east = $this->district('East District');

        $psds = $this->psds([$north, $south]);

        $alpha = $this->school($north, 'Alpha North School', '100001');
        $beta = $this->school($south, 'Beta South School', '200001');
        $eastSchool = $this->school($east, 'East Ridge School', '300001');

        // The unassigned school has a complete set of documents — and still
        // must not appear anywhere in the report.
        $this->aip($alpha, $year, AipPlan::STATUS_DRAFT);
        $this->aip($eastSchool, $year, AipPlan::STATUS_APPROVED);

        $dashboard = (new PsdsMonitoringReport($psds))->dashboard(['year' => $year]);

        $this->assertSame(
            ['Alpha North School', 'Beta South School'],
            $dashboard['rows']->pluck('school_name')->all(),
        );
        $this->assertSame(2, $dashboard['summary']['schools']);

        $this->assertSame(1, $dashboard['summary']['aip'][PsdsMonitoringReport::DOC_IN_PROGRESS]);
        $this->assertSame(1, $dashboard['summary']['aip'][PsdsMonitoringReport::DOC_NOT_SUBMITTED]);

        $this->assertSame(PsdsMonitoringReport::OVERALL_IN_PROGRESS, $dashboard['rows'][0]['overall_status']);
        $this->assertSame(PsdsMonitoringReport::OVERALL_NOT_STARTED, $dashboard['rows'][1]['overall_status']);

        $this->assertSame(
            ['North District', 'South District'],
            $dashboard['coverage']->map(fn (array $coverage): string => $coverage['district']->name)->all(),
        );
    }

    /* ------------------------------------------------------- status mapping */

    public function test_each_document_maps_to_the_monitoring_states(): void
    {
        $year = (int) now()->year;
        $previous = $year - 1;
        $north = $this->district('North District');
        $psds = $this->psds([$north]);

        $complete = $this->school($north, 'Complete School', '100001');
        $completeHead = $this->schoolHead($complete);
        $this->aip($complete, $year, AipPlan::STATUS_APPROVED);
        $this->opcrf($completeHead, OpcrfSubmission::STATUS_APPROVED, $year);
        $this->wfpPlan($complete, $year, WfpPlan::STATUS_FINALIZED);
        // A formal plan wins over an upload; an archived or older-year
        // returned OPCRF must not re-flag the school.
        $this->wfpUpload($completeHead, 'CY '.$year);
        $this->opcrf($completeHead, OpcrfSubmission::STATUS_RETURNED, $year, ['archived_at' => now()]);
        $this->opcrf($completeHead, OpcrfSubmission::STATUS_RETURNED, $previous);

        $returned = $this->school($north, 'Returned School', '100002');
        $returnedHead = $this->schoolHead($returned);
        $this->aip($returned, $year, AipPlan::STATUS_RETURNED);
        $this->opcrf($returnedHead, OpcrfSubmission::STATUS_RETURNED, $year);
        $this->wfpPlan($returned, $year, WfpPlan::STATUS_RETURNED);

        $draft = $this->school($north, 'Draft School', '100003');
        $this->aip($draft, $year, AipPlan::STATUS_DRAFT);
        $this->wfpPlan($draft, $year, WfpPlan::STATUS_DRAFT);

        $uploaded = $this->school($north, 'Uploaded School', '100004');
        $this->wfpUpload($this->schoolHead($uploaded), 'CY '.$year);

        $rows = (new PsdsMonitoringReport($psds))
            ->dashboard(['year' => $year])['rows']
            ->keyBy('school_name');

        $this->assertSame(PsdsMonitoringReport::DOC_SUBMITTED, $rows['Complete School']['aip']['status']);
        $this->assertSame(PsdsMonitoringReport::DOC_SUBMITTED, $rows['Complete School']['opcrf']['status']);
        $this->assertSame(PsdsMonitoringReport::DOC_SUBMITTED, $rows['Complete School']['wfp']['status']);
        $this->assertSame('Approved', $rows['Complete School']['wfp']['detail_label']);
        $this->assertSame(PsdsMonitoringReport::OVERALL_COMPLETE, $rows['Complete School']['overall_status']);

        $this->assertSame(PsdsMonitoringReport::DOC_NEEDS_REVISION, $rows['Returned School']['aip']['status']);
        $this->assertSame(PsdsMonitoringReport::DOC_NEEDS_REVISION, $rows['Returned School']['opcrf']['status']);
        $this->assertSame(PsdsMonitoringReport::DOC_NEEDS_REVISION, $rows['Returned School']['wfp']['status']);
        $this->assertSame(PsdsMonitoringReport::OVERALL_NEEDS_REVISION, $rows['Returned School']['overall_status']);

        $this->assertSame(PsdsMonitoringReport::DOC_IN_PROGRESS, $rows['Draft School']['aip']['status']);
        $this->assertSame(PsdsMonitoringReport::DOC_IN_PROGRESS, $rows['Draft School']['wfp']['status']);
        $this->assertSame(PsdsMonitoringReport::OVERALL_IN_PROGRESS, $rows['Draft School']['overall_status']);

        $this->assertSame(PsdsMonitoringReport::DOC_SUBMITTED, $rows['Uploaded School']['wfp']['status']);
        $this->assertSame('Workbook uploaded', $rows['Uploaded School']['wfp']['detail_label']);
        $this->assertNotNull($rows['Uploaded School']['wfp']['submitted_at']);
        $this->assertSame(PsdsMonitoringReport::OVERALL_PARTIALLY_SUBMITTED, $rows['Uploaded School']['overall_status']);
    }

    /* ------------------------------------------------ summary and coverage */

    public function test_the_summary_counts_each_status_and_district_coverage_is_derived_from_the_same_rows(): void
    {
        $year = (int) now()->year;
        $north = $this->district('North District');
        $psds = $this->psds([$north]);

        $complete = $this->school($north, 'Complete School', '100001');
        $completeHead = $this->schoolHead($complete);
        $this->aip($complete, $year, AipPlan::STATUS_APPROVED);
        $this->opcrf($completeHead, OpcrfSubmission::STATUS_APPROVED, $year);
        $this->wfpPlan($complete, $year, WfpPlan::STATUS_FINALIZED);

        $revision = $this->school($north, 'Revision School', '100002');
        $this->aip($revision, $year, AipPlan::STATUS_APPROVED);
        $this->opcrf($this->schoolHead($revision), OpcrfSubmission::STATUS_RETURNED, $year);

        $progress = $this->school($north, 'Progress School', '100003');
        $this->aip($progress, $year, AipPlan::STATUS_DRAFT);

        $partial = $this->school($north, 'Partial School', '100004');
        $this->aip($partial, $year, AipPlan::STATUS_APPROVED);

        $this->school($north, 'Empty School', '100005');

        $dashboard = (new PsdsMonitoringReport($psds))->dashboard(['year' => $year]);
        $summary = $dashboard['summary'];

        $this->assertSame(5, $summary['schools']);

        $this->assertSame(1, $summary['overall'][PsdsMonitoringReport::OVERALL_COMPLETE]);
        $this->assertSame(1, $summary['overall'][PsdsMonitoringReport::OVERALL_NEEDS_REVISION]);
        $this->assertSame(1, $summary['overall'][PsdsMonitoringReport::OVERALL_IN_PROGRESS]);
        $this->assertSame(1, $summary['overall'][PsdsMonitoringReport::OVERALL_PARTIALLY_SUBMITTED]);
        $this->assertSame(1, $summary['overall'][PsdsMonitoringReport::OVERALL_NOT_STARTED]);

        $this->assertSame(3, $summary['aip'][PsdsMonitoringReport::DOC_SUBMITTED]);
        $this->assertSame(1, $summary['aip'][PsdsMonitoringReport::DOC_IN_PROGRESS]);
        $this->assertSame(1, $summary['aip'][PsdsMonitoringReport::DOC_NOT_SUBMITTED]);

        $this->assertSame(1, $summary['opcrf'][PsdsMonitoringReport::DOC_SUBMITTED]);
        $this->assertSame(1, $summary['opcrf'][PsdsMonitoringReport::DOC_NEEDS_REVISION]);
        $this->assertSame(3, $summary['opcrf'][PsdsMonitoringReport::DOC_NOT_SUBMITTED]);

        $this->assertSame(1, $summary['wfp'][PsdsMonitoringReport::DOC_SUBMITTED]);
        $this->assertSame(4, $summary['wfp'][PsdsMonitoringReport::DOC_NOT_SUBMITTED]);

        $coverage = $dashboard['coverage']->first();
        $this->assertSame('North District', $coverage['district']->name);
        $this->assertSame(5, $coverage['schools']);
        // Complete's three documents + the two AIPs = 5 out of 15.
        $this->assertSame(5, $coverage['submitted']);
        $this->assertSame(15, $coverage['expected']);
        $this->assertSame(33, $coverage['percent']);
        $this->assertSame(1, $coverage['complete']);
        $this->assertSame(1, $coverage['needs_revision']);
    }

    /* ------------------------------------------------------------ filters */

    public function test_filters_and_search_narrow_the_table_without_rewriting_the_scope_summary(): void
    {
        $year = (int) now()->year;
        $north = $this->district('North District');
        $south = $this->district('South District');
        $psds = $this->psds([$north, $south]);

        $alpha = $this->school($north, 'Alpha North School', '100001');
        $beta = $this->school($north, 'Beta North School', '100002');
        $gamma = $this->school($north, 'Gamma North School', '100003');
        $delta = $this->school($south, 'Delta South School', '200001');

        $this->aip($alpha, $year, AipPlan::STATUS_APPROVED);
        $this->aip($beta, $year, AipPlan::STATUS_DRAFT);
        $this->aip($gamma, $year, AipPlan::STATUS_RETURNED);

        $report = new PsdsMonitoringReport($psds);

        $all = $report->dashboard(['year' => $year]);
        $this->assertSame(4, $all['summary']['schools']);
        $this->assertCount(4, $all['rows']);

        $byDistrict = $report->dashboard(['year' => $year, 'district' => $north->id]);
        $this->assertSame(
            ['Alpha North School', 'Beta North School', 'Gamma North School'],
            $byDistrict['rows']->pluck('school_name')->all(),
        );
        $this->assertSame(3, $byDistrict['summary']['schools']);

        $bySchool = $report->dashboard(['year' => $year, 'school' => $beta->id]);
        $this->assertSame(['Beta North School'], $bySchool['rows']->pluck('school_name')->all());

        $byName = $report->dashboard(['year' => $year, 'search' => 'beta']);
        $this->assertSame(['Beta North School'], $byName['rows']->pluck('school_name')->all());

        $byCode = $report->dashboard(['year' => $year, 'search' => '100003']);
        $this->assertSame(['Gamma North School'], $byCode['rows']->pluck('school_name')->all());

        // A document-status filter narrows the table only: the summary still
        // describes the district/school/year/search scope.
        $byAip = $report->dashboard(['year' => $year, 'aip' => PsdsMonitoringReport::DOC_NEEDS_REVISION]);
        $this->assertSame(['Gamma North School'], $byAip['rows']->pluck('school_name')->all());
        $this->assertSame(4, $byAip['summary']['schools']);
        $this->assertSame(1, $byAip['summary']['aip'][PsdsMonitoringReport::DOC_SUBMITTED]);

        $byOverall = $report->dashboard(['year' => $year, 'overall' => PsdsMonitoringReport::OVERALL_NEEDS_REVISION]);
        $this->assertSame(['Gamma North School'], $byOverall['rows']->pluck('school_name')->all());

        $combined = $report->dashboard([
            'year' => $year,
            'aip' => PsdsMonitoringReport::DOC_SUBMITTED,
            'overall' => PsdsMonitoringReport::OVERALL_PARTIALLY_SUBMITTED,
        ]);
        $this->assertSame(['Alpha North School'], $combined['rows']->pluck('school_name')->all());

        // Unknown status values are ignored, not trusted.
        $unknown = $report->dashboard(['year' => $year, 'aip' => 'not-a-status']);
        $this->assertCount(4, $unknown['rows']);

        // No match is an empty table, not an error.
        $noMatch = $report->dashboard(['year' => $year, 'search' => 'no school by this name']);
        $this->assertCount(0, $noMatch['rows']);
    }

    public function test_crafted_district_and_school_filters_cannot_widen_the_scope(): void
    {
        $year = (int) now()->year;
        $north = $this->district('North District');
        $east = $this->district('East District');
        $psds = $this->psds([$north]);

        $alpha = $this->school($north, 'Alpha North School', '100001');
        $eastSchool = $this->school($east, 'East Ridge School', '300001');

        $report = new PsdsMonitoringReport($psds);

        // A district outside the assignment is a filter that matches nothing,
        // never a door into that district's schools.
        $craftedDistrict = $report->dashboard(['year' => $year, 'district' => $east->id]);
        $this->assertCount(0, $craftedDistrict['rows']);
        $this->assertSame(0, $craftedDistrict['summary']['schools']);
        $this->assertCount(0, $craftedDistrict['coverage']);

        // The same holds for a school id outside the assignment.
        $craftedSchool = $report->dashboard(['year' => $year, 'school' => $eastSchool->id]);
        $this->assertCount(0, $craftedSchool['rows']);
        $this->assertCount(0, $craftedSchool['coverage']->where('schools', '>', 0));

        // And for a malformed district id — it is simply no filter.
        $malformed = $report->dashboard(['year' => $year, 'district' => 'abc']);
        $this->assertSame(['Alpha North School'], $malformed['rows']->pluck('school_name')->all());

        // The assigned district still works.
        $assigned = $report->dashboard(['year' => $year, 'district' => $north->id]);
        $this->assertSame(['Alpha North School'], $assigned['rows']->pluck('school_name')->all());
        $this->assertSame($alpha->id, $assigned['rows'][0]['school_id']);
    }

    /* ------------------------------------------------------------ details */

    public function test_the_detail_modal_is_read_only_and_refuses_schools_outside_the_assignment(): void
    {
        $year = (int) now()->year;
        $previous = $year - 1;
        $north = $this->district('North District');
        $east = $this->district('East District');
        $psds = $this->psds([$north]);
        $reviewer = User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]);

        $school = $this->school($north, 'Alpha North School', '100001');
        $head = $this->schoolHead($school);

        $aip = $this->aip($school, $year, AipPlan::STATUS_APPROVED);
        AipReview::create([
            'aip_plan_id' => $aip->id,
            'reviewer_id' => $reviewer->id,
            'action' => AipReview::ACTION_COMPLIANCE,
            'previous_status' => AipPlan::STATUS_PENDING,
            'new_status' => AipPlan::STATUS_APPROVED,
            'remarks' => 'AIP remark from reviewer',
            'reviewed_at' => now()->subDay(),
        ]);

        $opcrf = $this->opcrf($head, OpcrfSubmission::STATUS_APPROVED, $year);
        OpcrfReview::create([
            'opcrf_submission_id' => $opcrf->id,
            'reviewer_id' => $reviewer->id,
            'action' => OpcrfReview::ACTION_COMPLIANCE_FINAL,
            'remarks' => 'OPCRF remark from reviewer',
            'reviewed_at' => now()->subDay(),
        ]);

        $wfp = $this->wfpPlan($school, $year, WfpPlan::STATUS_FINALIZED);
        WfpApproval::create([
            'wfp_plan_id' => $wfp->id,
            'decision' => WfpApproval::DECISION_APPROVED,
            'decided_by' => $reviewer->id,
            'remarks' => 'WFP remark from reviewer',
            'decided_at' => now()->subDay(),
        ]);

        $eastSchool = $this->school($east, 'East Ridge School', '300001');

        $component = Livewire::actingAs($psds)->test(PsdsMonitoring::class);

        $component->assertSet('showDetail', false)
            ->call('openDetails', $school->id)
            ->assertSet('showDetail', true)
            ->assertSet('detailSchoolId', $school->id)
            ->assertSee('Alpha North School')
            ->assertSee('AIP remark from reviewer')
            ->assertSee('OPCRF remark from reviewer')
            ->assertSee('WFP remark from reviewer')
            ->assertSee('read-only monitoring view')
            ->call('closeDetails')
            ->assertSet('showDetail', false)
            ->assertSet('detailSchoolId', null);

        // A crafted school id outside the assignment is refused before any
        // of its data is read.
        $component->call('openDetails', $eastSchool->id)->assertNotFound();
    }

    /* ----------------------------------------------------------- pagination */

    public function test_the_school_table_is_paginated_in_twelve_row_pages(): void
    {
        $north = $this->district('North District');
        $psds = $this->psds([$north]);

        foreach (range(1, 13) as $index) {
            $this->school(
                $north,
                sprintf('School %02d', $index),
                (string) (100000 + $index),
            );
        }

        $component = Livewire::actingAs($psds)->test(PsdsMonitoring::class);

        $this->assertSame(13, $component->instance()->paginatedRows()->total());
        $this->assertCount(12, $component->instance()->paginatedRows()->items());
        $component->assertSeeHtml('class="users-pagination"');

        $component->call('gotoPage', 2);

        $this->assertSame(2, $component->instance()->paginatedRows()->currentPage());
        $this->assertCount(1, $component->instance()->paginatedRows()->items());
    }

    /* -------------------------------------------------------------- export */

    public function test_the_csv_export_is_scoped_filtered_and_utf8_ready(): void
    {
        $year = (int) now()->year;
        $north = $this->district('North District');
        $east = $this->district('East District');
        $psds = $this->psds([$north]);

        $alpha = $this->school($north, 'Alpha North School', '100001');
        $beta = $this->school($north, 'Beta North School', '100002');
        $eastSchool = $this->school($east, 'East Ridge School', '300001');

        $this->aip($alpha, $year, AipPlan::STATUS_APPROVED);
        $this->aip($beta, $year, AipPlan::STATUS_DRAFT);
        $this->aip($eastSchool, $year, AipPlan::STATUS_APPROVED);

        $response = $this->actingAs($psds)->get(route('psds.report', ['year' => $year]));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));

        $filename = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('psds-submission-monitoring-'.$year.'-', $filename);
        $this->assertStringContainsString('.csv', $filename);

        $body = (string) $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'The CSV must start with a UTF-8 BOM.');

        $this->assertStringContainsString('Reporting Period', $body);
        $this->assertStringContainsString('Alpha North School', $body);
        $this->assertStringContainsString('Beta North School', $body);
        $this->assertStringNotContainsString('East Ridge School', $body);

        // The same filters as the screen: a search narrows the export.
        $filtered = (string) $this->actingAs($psds)
            ->get(route('psds.report', ['year' => $year, 'q' => 'Alpha']))
            ->getContent();

        $this->assertStringContainsString('Alpha North School', $filtered);
        $this->assertStringNotContainsString('Beta North School', $filtered);

        // A district outside the assignment exports nothing at all.
        $crafted = (string) $this->actingAs($psds)
            ->get(route('psds.report', ['district' => $east->id]))
            ->getContent();

        $this->assertStringNotContainsString('Alpha North School', $crafted);
        $this->assertStringNotContainsString('East Ridge School', $crafted);

        // Only the PSDS role may export.
        $this->actingAs(User::factory()->create(['role' => 'administrator', 'is_superadmin' => false]))
            ->get(route('psds.report'))
            ->assertNotFound();

        Auth::logout();
        $this->get(route('psds.report'))->assertRedirect(route('login'));
    }

    /* -------------------------------------------------------- empty states */

    public function test_the_page_explains_an_empty_assignment_or_an_empty_result(): void
    {
        $noDistrict = $this->psds();

        Livewire::actingAs($noDistrict)
            ->test(PsdsMonitoring::class)
            ->assertSee('No districts assigned yet')
            ->assertDontSee('District coverage');

        $north = $this->district('North District');
        $noSchools = $this->psds([$north]);

        Livewire::actingAs($noSchools)
            ->test(PsdsMonitoring::class)
            ->assertSee('No schools in your districts yet')
            ->assertDontSee('No districts assigned yet');

        $school = $this->school($north, 'Alpha North School', '100001');
        $withSchool = $this->psds([$north]);

        Livewire::actingAs($withSchool)
            ->test(PsdsMonitoring::class)
            ->assertSee('Alpha North School')
            ->set('search', 'no school by this name')
            ->assertSee('No schools match these filters');
    }

    /* ------------------------------------------------------------- periods */

    public function test_the_reporting_year_defaults_to_the_current_year_and_all_years_uses_the_latest_on_file(): void
    {
        $current = (int) now()->year;
        $previous = $current - 1;
        $north = $this->district('North District');
        $psds = $this->psds([$north]);
        $school = $this->school($north, 'Alpha North School', '100001');

        // Only the previous year has a plan. The page opens on the current
        // year, so the school reads as not submitted until "All years".
        $this->aip($school, $previous, AipPlan::STATUS_APPROVED);

        $component = Livewire::actingAs($psds)->test(PsdsMonitoring::class);

        $component->assertSet('yearFilter', (string) $current);
        $this->assertSame(
            PsdsMonitoringReport::DOC_NOT_SUBMITTED,
            $component->instance()->reportData()['rows']->first()['aip']['status'],
        );

        $component->set('yearFilter', '');
        $this->assertSame(
            PsdsMonitoringReport::DOC_SUBMITTED,
            $component->instance()->reportData()['rows']->first()['aip']['status'],
        );

        $component->set('yearFilter', (string) $previous);
        $this->assertSame(
            PsdsMonitoringReport::DOC_SUBMITTED,
            $component->instance()->reportData()['rows']->first()['aip']['status'],
        );

        // A malformed year is treated as "all years", not as a data leak or
        // an error.
        $component->set('yearFilter', 'not-a-year');
        $this->assertSame(
            PsdsMonitoringReport::DOC_SUBMITTED,
            $component->instance()->reportData()['rows']->first()['aip']['status'],
        );
    }

    public function test_review_periods_are_parsed_to_their_last_four_digit_year(): void
    {
        $this->assertSame(2026, PsdsMonitoringReport::yearFromText('January to December 2026'));
        $this->assertSame(2026, PsdsMonitoringReport::yearFromText('SY 2025-2026'));
        $this->assertSame(2024, PsdsMonitoringReport::yearFromText('CY 2024'));
        $this->assertSame(2026, PsdsMonitoringReport::yearFromText('January 2026 to March 2026'));
        $this->assertNull(PsdsMonitoringReport::yearFromText('Annual Report'));
        $this->assertNull(PsdsMonitoringReport::yearFromText(''));
        $this->assertNull(PsdsMonitoringReport::yearFromText(null));

        // Both documents fall back to their date when the free text carries
        // no year at all.
        $previous = (int) now()->year - 1;
        $north = $this->district('North District');
        $school = $this->school($north, 'Alpha North School', '100001');
        $head = $this->schoolHead($school);

        $opcrf = $this->opcrf($head, OpcrfSubmission::STATUS_PENDING, $previous, [
            'review_period' => 'Annual Report',
            'submitted_at' => Carbon::create($previous, 6, 1, 12),
        ]);
        $upload = $this->wfpUpload($head, '', Carbon::create($previous, 6, 1, 12));

        $this->assertSame($previous, PsdsMonitoringReport::opcrfYear($opcrf));
        $this->assertSame($previous, PsdsMonitoringReport::wfpUploadYear($upload));
    }
}
