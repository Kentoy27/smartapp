<?php

namespace Tests\Feature;

use App\Livewire\WfpGenerateButton;
use App\Livewire\WfpPlanEditor;
use App\Models\AccountClassification;
use App\Models\District;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\School;
use App\Models\SchoolAllotment;
use App\Models\User;
use App\Models\WfpApproval;
use App\Models\WfpExportAudit;
use App\Models\WfpPlan;
use App\Models\WfpReportCheck;
use App\Models\WfpSubmission;
use App\Support\PurePhpZipReader;
use App\Support\SchoolDivision;
use App\Support\WfpGenerator;
use App\Support\WfpAccountCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsWfpTemplates;
use Tests\TestCase;

class WfpExportOptionsTest extends TestCase
{
    use BuildsWfpTemplates;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n1cAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function school(string $name): School
    {
        $district = District::create(['name' => 'District '.$name]);

        return School::create(['district_id' => $district->id, 'name' => $name]);
    }

    private function user(string $role, string $name, ?School $school = null, bool $superadmin = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'role' => $role,
            'is_superadmin' => $superadmin,
            'school_id' => $school?->id,
            'position' => $name.' Position',
        ]);
    }

    private function registerSignature(User $user): void
    {
        $path = 'signatures/'.$user->id.'/signature.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));
        $user->forceFill([
            'signature_path' => $path,
            'signature_original_name' => 'signature.png',
            'signature_mime_type' => 'image/png',
        ])->save();
    }

    private function generatedPlan(User $head, int $year = 2026): WfpPlan
    {
        $matrix = MonthlyExpenditureMatrix::create([
            'school_id' => $head->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => $year,
            'month' => 1,
            'status' => MonthlyExpenditureMatrix::STATUS_DRAFT,
            'version' => 1,
            'created_by' => $head->id,
        ]);

        $matrix->items()->create([
            'position' => 1,
            'section' => MonthlyExpenditureMatrix::SECTION_OPERATIONS,
            'objective' => 'Keep the school operating',
            'activity_description' => 'Purchase office supplies',
            'quantity' => 4,
            'unit' => 'ream',
            'unit_cost' => 300,
            'account_title' => 'Office Supplies',
            'account_code' => '5020301000',
            'funding_source' => 'MOOE',
            'organizational_classification' => 'School',
            'activity_movs' => 'PO, RIS',
        ]);

        $plan = WfpPlan::create([
            'school_id' => $head->school_id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => $year,
            'status' => WfpPlan::STATUS_DRAFT,
        ]);

        WfpGenerator::generate($plan);

        return $plan->fresh();
    }

    private function signatureTemplate(): string
    {
        $name = 'test-wfp-export-'.uniqid().'.xlsx';
        file_put_contents(storage_path('forms/'.$name), $this->wfpTemplateBytes(3, null, false, true));
        config(['wfp.template_file' => $name]);

        return $name;
    }

    /**
     * @return array<string, string>
     */
    private function workbookParts(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wfp-export-');
        file_put_contents($path, $bytes);

        try {
            return (new PurePhpZipReader($path))->readAll();
        } finally {
            unlink($path);
        }
    }

    private function approvedPlanWithWorkflowSigners(): array
    {
        $school = $this->school('Export Test School');
        $head = $this->user('user', 'School Head', $school);
        $bookkeeper = $this->user('bookkeeper', 'Bella Reyes', $school);
        $budget = $this->user('budget', 'Benny Santos');
        $superadmin = $this->user('superadmin', 'Grace Villanueva', null, true);

        foreach ([$head, $bookkeeper, $budget, $superadmin] as $signer) {
            $this->registerSignature($signer);
        }

        $plan = $this->generatedPlan($head);

        WfpReportCheck::create([
            'school_id' => $school->id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => $plan->year,
            'checked_by' => $bookkeeper->id,
            'checked_at' => now(),
        ]);
        SchoolAllotment::create([
            'school_id' => $school->id,
            'school_division' => SchoolDivision::ELEMENTARY,
            'year' => $plan->year,
            'amount' => 50000,
            'certified_by' => $budget->id,
            'certified_at' => now(),
        ]);

        $plan->submitForApproval($head);
        $plan->markApproved($superadmin);

        return [$head, $plan->fresh(), $bookkeeper, $budget, $superadmin];
    }

    public function test_signed_and_unsigned_exports_use_existing_workflow_records_and_audit_each_download(): void
    {
        $template = $this->signatureTemplate();

        try {
            [$head, $plan, $bookkeeper, $budget, $superadmin] = $this->approvedPlanWithWorkflowSigners();
            Livewire::actingAs($head)
                ->test(WfpPlanEditor::class, ['plan' => $plan])
                ->assertSee('Export with E-Signatures')
                ->assertSee('Export without E-Signatures');
            Livewire::actingAs($bookkeeper)
                ->test(WfpPlanEditor::class, ['plan' => $plan])
                ->assertSee('Export with E-Signatures')
                ->assertSee('Export without E-Signatures');

            $originalPath = 'wfp/'.$head->id.'/uploaded-original.xlsx';
            Storage::disk('local')->put($originalPath, 'original-upload-bytes');
            WfpSubmission::create([
                'user_id' => $head->id,
                'original_file_name' => 'uploaded-original.xlsx',
                'file_path' => $originalPath,
                'file_size' => strlen('original-upload-bytes'),
                'file_type' => 'xlsx',
                'school_year' => 'CY 2026',
                'status' => WfpSubmission::STATUS_UPLOADED,
                'uploaded_at' => now(),
            ]);

            $signed = $this->actingAs($head)->get(route('wfp.plans.export', ['plan' => $plan->id]));
            $signed->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="WFP_CY2026_Signed.xlsx"');
            $signedParts = $this->workbookParts((string) $signed->getContent());
            $images = array_filter(array_keys($signedParts), fn (string $path): bool => str_starts_with($path, 'xl/media/'));
            $drawings = array_filter(array_keys($signedParts), fn (string $path): bool => preg_match('#^xl/drawings/drawing\d+\.xml$#', $path) === 1);
            $this->assertCount(4, $images);
            $this->assertCount(1, $drawings);

            $drawingXml = $signedParts[array_values($drawings)[0]];
            foreach ([$head->name, $bookkeeper->name, $budget->name, $superadmin->name] as $signerName) {
                $this->assertStringContainsString($signerName, $drawingXml);
            }

            $unsigned = $this->actingAs($head)->get(route('wfp.plans.export', [
                'plan' => $plan->id,
                'signatures' => 'without',
            ]));
            $unsigned->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="WFP_CY2026_Unsigned.xlsx"');
            $unsignedParts = $this->workbookParts((string) $unsigned->getContent());
            $this->assertSame([], array_filter(array_keys($unsignedParts), fn (string $path): bool => str_starts_with($path, 'xl/media/')));
            $sheet = $unsignedParts['xl/worksheets/sheet1.xml'];
            foreach ([$head->name, $bookkeeper->name, $budget->name, $superadmin->name] as $signerName) {
                $this->assertStringNotContainsString($signerName, $sheet);
            }

            $this->assertSame(WfpPlan::STATUS_FINALIZED, $plan->fresh()->status);
            $this->assertSame(1, WfpApproval::query()->where('wfp_plan_id', $plan->id)->count());
            $this->assertSame('original-upload-bytes', Storage::disk('local')->get($originalPath));
            $this->assertDatabaseCount('wfp_export_audits', 2);
            $this->assertDatabaseHas('wfp_export_audits', [
                'user_id' => $head->id,
                'role' => 'user',
                'school_id' => $head->school_id,
                'year' => 2026,
                'wfp_plan_id' => $plan->id,
                'template_version' => $plan->template_version,
                'export_type' => 'signed',
            ]);
            $this->assertDatabaseHas('wfp_export_audits', [
                'user_id' => $head->id,
                'wfp_plan_id' => $plan->id,
                'export_type' => 'unsigned',
            ]);
        } finally {
            @unlink(storage_path('forms/'.$template));
        }
    }

    public function test_bookkeeper_and_budget_can_export_a_plan_with_the_school_heads_signature(): void
    {
        $template = $this->signatureTemplate();

        try {
            [$head, $plan, $bookkeeper, $budget] = $this->approvedPlanWithWorkflowSigners();

            foreach ([$bookkeeper, $budget] as $reader) {
                $response = $this->actingAs($reader)->get(route('wfp.plans.export', ['plan' => $plan->id]));
                $response->assertOk();

                $parts = $this->workbookParts((string) $response->getContent());
                $drawing = collect($parts)
                    ->first(fn (string $contents, string $path): bool => preg_match('#^xl/drawings/drawing\\d+\\.xml$#', $path) === 1);

                $this->assertIsString($drawing);
                $this->assertStringContainsString($head->name, $drawing);
                $this->assertDatabaseHas('wfp_export_audits', [
                    'user_id' => $reader->id,
                    'wfp_plan_id' => $plan->id,
                    'export_type' => 'signed',
                ]);
            }
        } finally {
            @unlink(storage_path('forms/'.$template));
        }
    }

    public function test_signed_export_warns_when_workflow_signatures_are_missing_and_unsigned_generated_file_is_audited(): void
    {
        $template = $this->signatureTemplate();

        try {
            $school = $this->school('Unsigned Test School');
            $head = $this->user('user', 'School Head', $school);
            AccountClassification::create([
                'account_title' => 'Office Supplies',
                'rca_code' => 'RCA-1',
                'uacs_sub_object_code' => 'SUB-1',
                'uacs_object_code' => '5020301000',
                'covering_ppas' => null,
                'is_active' => true,
            ]);
            $expectedName = substr(WfpAccountCodeGenerator::downloadName($head, (int) now()->year), 0, -5)
                .'_Unsigned.xlsx';
            $component = Livewire::actingAs($head)->test(WfpGenerateButton::class);

            $component
                ->assertSee('Generate and Download WFP (With E-Signatures)')
                ->assertSee('Generate and Download WFP (Without E-Signatures)')
                ->assertSee('Senior Bookkeeper')
                ->assertSee('Budget Officer')
                ->assertSee('Schools Division Superintendent')
                ->assertSee('Continue with the available signatures?')
                ->call('download', 'without')
                ->assertFileDownloaded($expectedName);

            $this->assertDatabaseHas('wfp_export_audits', [
                'user_id' => $head->id,
                'role' => 'user',
                'school_id' => $school->id,
                'year' => now()->year,
                'wfp_plan_id' => null,
                'export_type' => 'unsigned',
            ]);
        } finally {
            @unlink(storage_path('forms/'.$template));
        }
    }

    public function test_signed_export_uses_available_signatures_without_substituting_a_missing_signer(): void
    {
        $template = $this->signatureTemplate();

        try {
            [$head, $plan, $bookkeeper, $budget, $superadmin] = $this->approvedPlanWithWorkflowSigners();
            Storage::disk('local')->delete((string) $budget->signature_path);

            Livewire::actingAs($head)
                ->test(WfpPlanEditor::class, ['plan' => $plan])
                ->assertSee('Budget Officer')
                ->assertSee('will remain unsigned');

            $response = $this->actingAs($head)->get(route('wfp.plans.export', ['plan' => $plan->id]));
            $response->assertOk();
            $parts = $this->workbookParts((string) $response->getContent());
            $images = array_filter(array_keys($parts), fn (string $path): bool => str_starts_with($path, 'xl/media/'));
            $this->assertCount(3, $images);

            $drawingXml = $parts['xl/drawings/drawing1.xml'];
            $this->assertStringContainsString($head->name, $drawingXml);
            $this->assertStringContainsString($bookkeeper->name, $drawingXml);
            $this->assertStringContainsString($superadmin->name, $drawingXml);
            $this->assertStringNotContainsString($budget->name, $drawingXml);
            $this->assertStringNotContainsString($budget->name, $drawingXml);
        } finally {
            @unlink(storage_path('forms/'.$template));
        }
    }

    public function test_a_reader_cannot_export_a_plan_outside_their_scope(): void
    {
        $template = $this->signatureTemplate();

        try {
            [$head, $plan] = $this->approvedPlanWithWorkflowSigners();
            $otherSchoolHead = $this->user('user', 'Other School Head', $this->school('Other School'));

            $this->actingAs($otherSchoolHead)->get(route('wfp.plans.export', $plan))->assertNotFound();
            $this->assertSame(0, WfpExportAudit::count());
        } finally {
            @unlink(storage_path('forms/'.$template));
        }
    }
}
